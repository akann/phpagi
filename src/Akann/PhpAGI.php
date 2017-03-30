<?php

namespace Akann;

class PhpAGI
{
    const AST_STATE_DOWN = 0;
    const AST_STATE_RESERVED = 1;
    const AST_STATE_OFFHOOK = 2;
    const AST_STATE_DIALING = 3;
    const AST_STATE_RING = 4;
    const AST_STATE_RINGING = 5;
    const AST_STATE_UP = 6;
    const AST_STATE_BUSY = 7;
    const AST_STATE_PRERING = 9;
    const AGIRES_OK = 200;
    const AUDIO_FILENO = 3;  // STDERR_FILENO + 1
    const AST_DIGIT_ANY = '0123456789#*';
    const AST_STATE_DIALING_OFFHOOK = 8;

    private $request;
    private $in;
    private $out;

    public function __construct($conffile = '')
    {
        $this->request = $this->getRequest();
        $this->audio   = $this->getAudio($this->request['agi_enhanced']);
    }
    private function getRequest()
    {
        $request = "";
        ob_implicit_flush(true);

        // open stdin & stdout
        $this->in = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        $this->out = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

        // read the request
        $str = fgets($this->in);
        while ($str != "\n") {
            $request[substr($str, 0, strpos($str, ':'))] = trim(substr($str, strpos($str, ':') + 1));
            $str = fgets($this->in);
        }
    }

    private function getAudio($agi_enhanced)
    {
        $audio = null;

        // open audio if eagi detected
        if ($agi_enhanced == '1.0') {
            if (file_exists('/proc/' . getmypid() . '/fd/3'))
                $audio = fopen('/proc/' . getmypid() . '/fd/3', 'r');
            elseif (file_exists('/dev/fd/3')) {
                // may need to mount fdescfs
                $audio = fopen('/dev/fd/3', 'r');
            } else {
                $this->conlog('Unable to open audio stream');
            }

            if ($this->audio) stream_set_blocking($this->audio, 0);
        }
        return $audio;
    }

    function evaluate($command)
    {
        $broken = array('code' => 500, 'result' => -1, 'data' => '');

        // write command
        if (!@fwrite($this->out, trim($command) . "\n")) return $broken;
        fflush($this->out);

        // Read result.  Occasionally, a command return a string followed by an extra new line.
        // When this happens, our script will ignore the new line, but it will still be in the
        // buffer.  So, if we get a blank line, it is probably the result of a previous
        // command.  We read until we get a valid result or asterisk hangs up.  One offending
        // command is SEND TEXT.
        $count = 0;
        do {
            $str = trim(fgets($this->in, 4096));
        } while ($str == '' && $count++ < 5);

        if ($count >= 5) {
            //          $this->conlog("evaluate error on read for $command");
            return $broken;
        }

        // parse result
        $ret['code'] = substr($str, 0, 3);
        $str = trim(substr($str, 3));

        if($str{0} == '-') // we have a multiline response!
        {
            $count = 0;
            $str = substr($str, 1) . "\n";
            $line = fgets($this->in, 4096);
            while(substr($line, 0, 3) != $ret['code'] && $count < 5)
            {
                $str .= $line;
                $line = fgets($this->in, 4096);
                $count = (trim($line) == '') ? $count + 1 : 0;
            }
            if($count >= 5)
            {
                //            $this->conlog("evaluate error on multiline read for $command");
                return $broken;
            }
        }

        $ret['result'] = NULL;
        $ret['data'] = '';
        if($ret['code'] != AGIRES_OK) // some sort of error
        {
            $ret['data'] = $str;
            $this->conlog(print_r($ret, true));
        }
        else // normal AGIRES_OK response
        {
            $parse = explode(' ', trim($str));
            $in_token = false;
            foreach($parse as $token)
            {
                if($in_token) // we previously hit a token starting with ')' but not ending in ')'
                {
                    $ret['data'] .= ' ' . trim($token, '() ');
                    if($token{strlen($token)-1} == ')') $in_token = false;
                }
                elseif($token{0} == '(')
                {
                    if($token{strlen($token)-1} != ')') $in_token = true;
                    $ret['data'] .= ' ' . trim($token, '() ');
                }
                elseif(strpos($token, '='))
                {
                    $token = explode('=', $token);
                    $ret[$token[0]] = $token[1];
                }
                elseif($token != '')
                    $ret['data'] .= ' ' . $token;
            }
            $ret['data'] = trim($ret['data']);
        }

        // log some errors
        if($ret['result'] < 0)
            $this->conlog("$command returned {$ret['result']}");

        return $ret;
    }

    function verbose($message, $level=1)
    {
        foreach(explode("\n", str_replace("\r\n", "\n", print_r($message, true))) as $msg)
        {
            @syslog(LOG_WARNING, $msg);
            $ret = $this->evaluate("VERBOSE \"$msg\" $level");
        }
        return $ret;
    }

    function conlog($str, $vbl=1)
    {
        static $busy = false;

        if($this->config['phpagi']['debug'] != false)
        {
            if(!$busy) // no conlogs inside conlog!!!
            {
                $busy = true;
                $this->verbose($str, $vbl);
                $busy = false;
            }
        }
    }

}
