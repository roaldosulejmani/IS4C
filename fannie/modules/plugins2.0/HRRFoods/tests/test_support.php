<?php
/*******************************************************************************

    HRR Foods Plugin — Minimal Test Harness

    PHPUnit is not always available in FANNIE deployments. This file
    provides a 30-line standalone harness:
        $t = new HRRTestRunner();
        $t->test('description', function () { $t->assertEquals(1, 1); });
        $t->run();
        exit($t->exitCode());

    Used by run_tests.php.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

class HRRTestRunner
{
    private $tests = array();
    private $failures = array();
    private $passed = 0;

    public function test($name, $fn)
    {
        $this->tests[] = array('name' => $name, 'fn' => $fn);
    }

    public function run()
    {
        foreach ($this->tests as $i => $t) {
            try {
                $fn = $t['fn'];
                $fn();
                $this->passed++;
                fwrite(STDOUT, "  [PASS] {$t['name']}\n");
            } catch (\Throwable $e) {
                $this->failures[] = $t['name'] . ': ' . $e->getMessage();
                fwrite(STDOUT, "  [FAIL] {$t['name']}: {$e->getMessage()}\n");
            }
        }
    }

    public function exitCode()
    {
        return empty($this->failures) ? 0 : 1;
    }

    public function summary()
    {
        return sprintf("Passed: %d, Failed: %d, Total: %d\n",
            $this->passed, count($this->failures), count($this->tests));
    }

    public function assertTrue($cond, $msg = 'expected true')
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    public function assertFalse($cond, $msg = 'expected false')
    {
        if ($cond) {
            throw new \RuntimeException($msg);
        }
    }

    public function assertEquals($a, $b, $msg = '')
    {
        if ($a !== $b) {
            throw new \RuntimeException($msg . ' (got ' . var_export($a, true) . ', want ' . var_export($b, true) . ')');
        }
    }

    public function assertNotNull($v, $msg = 'expected non-null')
    {
        if ($v === null) {
            throw new \RuntimeException($msg);
        }
    }

    public function assertContains($needle, $haystack, $msg = '')
    {
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack, true)) {
                throw new \RuntimeException($msg . ' (array does not contain ' . var_export($needle, true) . ')');
            }
            return;
        }
        if (is_string($haystack)) {
            if (strpos($haystack, (string)$needle) === false) {
                throw new \RuntimeException($msg . ' (string does not contain ' . var_export($needle, true) . ')');
            }
            return;
        }
        throw new \RuntimeException('assertContains: unsupported haystack type');
    }
}