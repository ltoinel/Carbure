<?php

use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testMessagesAreWrittenWithUid()
    {
        $message = 'Unit test message ' . uniqid();
        Logger::warn($message, ['key' => 'value']);
        Logger::flush();

        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $content = file_get_contents($file);

        $this->assertStringContainsString($message, $content);
        $this->assertStringContainsString('WARNING : ' . Logger::getUID(), $content);
        $this->assertStringContainsString('[key] => value', $content);
    }

    public function testBufferIsFlushedWhenFull()
    {
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $message = 'Buffered message ' . uniqid();

        Logger::setBufferSize(1);
        try {
            Logger::info($message);
            // Written without an explicit flush
            $this->assertStringContainsString($message, file_get_contents($file));
        } finally {
            Logger::setBufferSize(50);
        }
    }

    public function testMessagesBelowTheLevelAreIgnored()
    {
        $minLevel = new ReflectionProperty(Logger::class, 'minLevel');
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $message = 'Ignored message ' . uniqid();

        Config::set('log_level', 'error');
        $minLevel->setValue(null, null);
        try {
            Logger::warn($message);
            Logger::flush();
            $this->assertStringNotContainsString($message, file_get_contents($file));
        } finally {
            Config::set('log_level', 'debug');
            $minLevel->setValue(null, null);
        }
    }
}
