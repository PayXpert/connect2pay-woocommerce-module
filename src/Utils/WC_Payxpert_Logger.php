<?php

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

class WC_Payxpert_Logger 
{
    const FOLDER = 'logs';
    const FILENAME = 'debug';

    const EMERGENCY = 1;
    const ALERT = 2;
    const CRITICAL = 3;
    const ERROR = 4;
    const WARN = 5;
    const NOTICE = 6;
    const INFO = 7;
    const DEBUG = 8;

    /**
     * @var array
     */
    protected static $levels = [
        self::EMERGENCY => 'EMERGENCY',
        self::ALERT => 'ALERT',
        self::CRITICAL => 'CRITICAL',
        self::ERROR => 'ERROR',
        self::WARN => 'WARNING',
        self::NOTICE => 'NOTICE',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
    ];

    /**
     * @return int|false
     */
    public static function emergency($msg)
    {
        return self::log($msg, self::EMERGENCY);
    }

    /**
     * @return int|false
     */
    public static function alert($msg)
    {
        return self::log($msg, self::ALERT);
    }

    /**
     * @return int|false
     */
    public static function critical($msg)
    {
        return self::log($msg, self::CRITICAL);
    }

    /**
     * @return int|false
     */
    public static function error($msg)
    {
        return self::log($msg, self::ERROR);
    }

    /**
     * @return int|false
     */
    public static function warn($msg)
    {
        return self::log($msg, self::WARN);
    }

    /**
     * @return int|false
     */
    public static function notice($msg)
    {
        return self::log($msg, self::NOTICE);
    }

    /**
     * @return int|false
     */
    public static function info($msg)
    {
        return self::log($msg, self::INFO);
    }

    /**
     * @return int|false
     */
    public static function debug($msg)
    {
        return self::log($msg, self::DEBUG);
    }

    /**
     * @return int|false
     */
    private static function log($msg, int $levelDegree)
    {
        if (!isset(self::$levels[$levelDegree])) {
            return false;
        }

        $debug = debug_backtrace(2);

        return file_put_contents(
            self::getLogFilePath(),
            sprintf(
                '[%s][%s][%s::%s] : %s' . PHP_EOL,
                date('Y-m-d H:i:s'),
                self::$levels[$levelDegree],
                $debug[2]['class'],
                $debug[2]['function'],
                $msg
            ),
            FILE_APPEND | LOCK_EX
        );
    }

    public static function getLogFilePath(): string
    {
        return __DIR__ . '/../../' . self::FOLDER . '/' . self::FILENAME . '.log';
    }
}
