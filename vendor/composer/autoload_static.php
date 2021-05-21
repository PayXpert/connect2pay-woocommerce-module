<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit3ee273c20de36975f4e26653906a26df
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'PayXpert\\Connect2Pay\\Tests\\' => 27,
            'PayXpert\\Connect2Pay\\' => 21,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'PayXpert\\Connect2Pay\\Tests\\' => 
        array (
            0 => __DIR__ . '/..' . '/payxpert/connect2pay/tests',
        ),
        'PayXpert\\Connect2Pay\\' => 
        array (
            0 => __DIR__ . '/..' . '/payxpert/connect2pay/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit3ee273c20de36975f4e26653906a26df::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit3ee273c20de36975f4e26653906a26df::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
