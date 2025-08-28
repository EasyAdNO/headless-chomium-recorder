<?php

declare(strict_types=1);

namespace EasyAdNO\HeadlessChromiumRecorder;

class Recorder
{
    private bool $is_recording = false;
    // disk format: double(timestamp) uint32_t(length_of_data) + char[length_of_data](data)
    private $recording_temp_file_handle = null;
    private string $recording_temp_file_path;
    // what's the difference between id and sessionId? idk! but they are different.
    private $sessionId;
    private $id;
    private $size_of_float = null; // basically always 8, IEEE 754 double precision
    function __construct(
        private \HeadlessChromium\Page $page
    ) {
        $this->size_of_float = strlen(pack("d", 0)); // 8 on all normal systems...
        $this->recording_temp_file_handle = tmpfile();
        if ($this->recording_temp_file_handle === false) {
            throw new \RuntimeException("Failed to create temp file for recording: " . var_export(error_get_last(), true));
        }
        $this->recording_temp_file_path = stream_get_meta_data($this->recording_temp_file_handle)['uri'];
    }
    public function startRecording(?string $format = null, ?int $quality = null, ?int $maxWidth = null, ?int $maxHeight = null, ?int $everyNthFrame = null): void
    {
        if ($this->is_recording) {
            throw new \LogicException("Recording already started!");
        }
        $this->page->getSession()->on('method:Page.screencastFrame', $this->method_Page_screencastFrame_Handler(...));
        $params = [];
        if ($format !== null) {
            $params['format'] = $format;
        }
        if ($quality !== null) {
            $params['quality'] = $quality;
        }
        if ($maxWidth !== null) {
            $params['maxWidth'] = $maxWidth;
        }
        if ($maxHeight !== null) {
            $params['maxHeight'] = $maxHeight;
        }
        if ($everyNthFrame !== null) {
            $params['everyNthFrame'] = $everyNthFrame;
        }

        $startScreencastResponse = $this->page->getSession()->sendMessageSync(new \HeadlessChromium\Communication\Message(
            'Page.startScreencast',
            $params
        ));
        $data = $startScreencastResponse->getData();
        $this->sessionId = $data['sessionId'];
        $this->id = $data['id']; // what's the difference between id and sessionId? idk! but they are different.
        $this->is_recording = true;
    }
    private function method_Page_screencastFrame_Handler(array $params): void
    {
        if (false) {
            // $params sample:
            $params = array(
                'data' => '<base64 image jpeg>',
                'metadata' => array(
                    'offsetTop' => 0,
                    'pageScaleFactor' => 1,
                    'deviceWidth' => 1920,
                    'deviceHeight' => 1080,
                    'scrollOffsetX' => 0,
                    'scrollOffsetY' => 0,
                    'timestamp' => 1693206269.851577,
                ),
                'sessionId' => 1,
            );
        }
        // need to send this message ASAP:
        // any delay in sending this message may cause animations to be laggy.
        $this->page->getSession()->sendMessage(new \HeadlessChromium\Communication\Message(
            'Page.screencastFrameAck',
            [
                'sessionId' => $params['sessionId'],
            ]
        ));
        $stringToWriteToDisk = base64_decode($params['data'], true);
        $stringToWriteToDisk = pack("d", $params['metadata']['timestamp']) . pack("L", strlen($stringToWriteToDisk)) . $stringToWriteToDisk;
        if (strlen($stringToWriteToDisk) !== ($written = fwrite($this->recording_temp_file_handle, $stringToWriteToDisk))) {
            throw new \RuntimeException("Could only write $written of " . strlen($stringToWriteToDisk) . " bytes to disk");
        }
    }
    public function stopRecording(): void
    {
        if (!$this->is_recording) {
            throw new \LogicException("Recording not started!");
        }
        $this->page->getSession()->sendMessageSync(new \HeadlessChromium\Communication\Message(
            'Page.stopScreencast',
            [
                'sessionId' => $this->sessionId,
            ]
        ));
        $this->is_recording = false;
    }


    public const FRAME_INDEX_TIMESTAMP = 0;
    public const FRAME_INDEX_OFFSET = 1;
    public const FRAME_INDEX_LENGTH = 2;
    /**
     * returns an array of frames, each frame is an array with keys:
     * [0]: float timestamp
     * [1]: int byte_offset (from start of file)
     * [2]: int byte_length
     * 
     * @return array
     * @throws RuntimeException if reading from the temp file fails
     * @throws LogicException fseek fails
     */
    public function getFrames(): array
    {
        $ret = [];
        rewind($this->recording_temp_file_handle);
        $read_chunk_size = $this->size_of_float + 4;
        $read_pos = 0;
        for (;;) {
            $chunk = fread($this->recording_temp_file_handle, $read_chunk_size);
            if ($chunk === false || $chunk === '') {
                // EOF
                break;
            }
            if (strlen($chunk) !== $read_chunk_size) {
                throw new \LogicException("... should be unreachable");
            }
            $read_pos += $read_chunk_size;
            $timestamp = unpack("d", substr($chunk, 0, $this->size_of_float))[1];
            $length_of_data = unpack("L", substr($chunk, $this->size_of_float, 4))[1];
            $ret[] = [
                self::FRAME_INDEX_TIMESTAMP => $timestamp,
                self::FRAME_INDEX_OFFSET => $read_pos,
                self::FRAME_INDEX_LENGTH => $length_of_data,
            ];
            if (0 !== fseek($this->recording_temp_file_handle, $length_of_data, SEEK_CUR)) {
                throw new \RuntimeException("fseek failed");
            }
            $read_pos += $length_of_data;
        }
        return $ret;
    }

    /**
     * get the path to the temp file where the recording is stored
     * 
     * @return string
     */
    public function getRecordingTempFilePath(): string
    {
        return $this->recording_temp_file_path;
    }

    /**
     * create a ffmpeg concat input string from frames
     * 
     * @param array $frames
     * @param float $last_frame_duration
     * 
     * @return string
     */
    public function generateFfmpegConcatDemuxerInputString(
        array $frames,
        float $last_frame_duration = 0.1 // pulled 0.1 out of my ass..
    ): string {
        if (!array_is_list($frames)) {
            throw new \InvalidArgumentException("frames is not a list of frames");
        }
        $ffmpegInputTxtString = '';
        for ($frameno = 0, $framecount = count($frames); $frameno < $framecount; $frameno += 1) {
            $frame = $frames[$frameno];
            $currentTimestamp = $frame[self::FRAME_INDEX_TIMESTAMP];
            $nextTimestamp = $frames[$frameno + 1][self::FRAME_INDEX_TIMESTAMP] ?? null;
            $duration = ($nextTimestamp === null) ? $last_frame_duration : ($nextTimestamp - $currentTimestamp);
            $ffmpegInputTxtString .= "file 'subfile,,start," . $frame[self::FRAME_INDEX_OFFSET] . ",end," . ($frame[self::FRAME_INDEX_OFFSET] + $frame[self::FRAME_INDEX_LENGTH]) . ",,:" . $this->recording_temp_file_path . "'\nduration " . number_format($duration, 5, '.', '') . "\n";
        }
        return $ffmpegInputTxtString;
    }

    public const GENERATE_VIDEO_1_DEFAULT_FFMPEG_ARGS = array(
        'ffmpeg_binary' => 'ffmpeg',
        '-y'  => '-y',
        '-f' => '-f concat',
        '-safe' => '-safe 0',
        '-protocol_whitelist' => '-protocol_whitelist "concat,ffconcat,file,subfile,data,crypto,tcp,tls"',
        '-i' => '-i escapeshellarg($ffmpegInputTxtFilePath)',
        '-fps_mode' => '-fps_mode vfr',
        '-qp' => '-qp 8',
        'output_file_path_quoted' => 'escapeshellarg($output_file_path)',
    );
    /**
     * create a video file from the recording
     * using ffmpeg..
     * 
     */
    public function generateVideo1(
        string $output_file_path = 'output.mp4',
        ?array $custom_args = null,
        ?float $last_frame_duration = 0.1
    ): void {
        $frames = $this->getFrames();
        $ffmpegInputTxtFileHandle = tmpfile();
        $ffmpegInputTxtFilePath = stream_get_meta_data($ffmpegInputTxtFileHandle)['uri'];
        fwrite($ffmpegInputTxtFileHandle, $this->generateFfmpegConcatDemuxerInputString($frames, $last_frame_duration ?? 0.1));
        $defaultArgs = self::GENERATE_VIDEO_1_DEFAULT_FFMPEG_ARGS;
        if (!empty($custom_args)) {
            foreach ($custom_args as $k => $v) {
                if ($v === null) {
                    unset($defaultArgs[$k]);
                } else {
                    $defaultArgs[$k] = $v;
                }
            }
        }
        if (($defaultArgs['-i'] ?? null) === self::GENERATE_VIDEO_1_DEFAULT_FFMPEG_ARGS['-i']) {
            $defaultArgs['-i'] = '-i ' . escapeshellarg($ffmpegInputTxtFilePath);
        }
        if (($defaultArgs['output_file_path_quoted'] ?? null) === self::GENERATE_VIDEO_1_DEFAULT_FFMPEG_ARGS['output_file_path_quoted']) {
            $defaultArgs['output_file_path_quoted'] = escapeshellarg($output_file_path);
        }

        $cmd = implode(" ", array_values($defaultArgs));
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Failed to create video! ffmpeg exit code: " . $exitCode);
        }
    }
}
