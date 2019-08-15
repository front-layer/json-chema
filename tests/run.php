<?php
declare(strict_types=1);

use \FrontLayer\JsonSchema\Validator;
use \FrontLayer\JsonSchema\Schema;
use \FrontLayer\JsonSchema\ValidationException;
use \FrontLayer\JsonSchema\SchemaException;

require __DIR__ . './../vendor/autoload.php';

class Tests
{
    /**
     * Collected tests
     * @var array
     */
    protected $collections = [];

    /**
     * Log
     * @var array
     */
    protected $log = [];

    /**
     * Ignore list
     * @var array
     */
    protected $ignores = [];

    /**
     * Collect all tests
     * @param string $directory
     * @param string $version
     */
    public function addCollection(string $directory, string $version = null): void
    {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($rii as $file) {
            /* @var $file SplFileInfo */

            if ($file->isDir()) {
                continue;
            }

            $this->collections[] = (object)[
                'file' => $file->getPathname(),
                'version' => $version,
                'content' => json_decode(file_get_contents($file->getPathname()))
            ];
        }
    }

    /**
     * Add ignore conditions to skip specific tests
     * @param string $msg
     */
    public function ignore(string $msg): void
    {
        $this->ignores[] = $msg;
    }

    /**
     * Run all tests
     */
    public function run(): void
    {
        // Run tests
        foreach ($this->collections as $collection) {
            foreach ($collection->content as $content) {
                $this->testSchema($collection, $content);

                if (!empty($content->tests)) {
                    foreach ($content->tests as $test) {
                        $this->testData($collection, $content, $test);
                    }
                }
            }
        }

        $this->showLog();
    }

    /**
     * Test provided schema
     * @param object $collection
     * @param object $content
     */
    protected function testSchema(object $collection, object $content): void
    {
        $exception = null;
        $valid = property_exists($content, 'tests') ? true : $content->valid;

        try {
            $schema = new Schema($content->schema, $collection->version);
            $validator = new Validator();
            $validator->validate('', $schema);
            $testResult = true;
        } catch (ValidationException $exception) {
            $testResult = true;
        } catch (SchemaException $exception) {
            $testResult = false;
        } catch (Exception $exception) {
            $this->log(false, $collection->file, $content->description, null, 'NON SCHEMA EXCEPTION: ' . $exception->getMessage());
            return;
        }
        $this->log($testResult === $valid, $collection->file, $content->description, null, $exception ? $exception->getMessage() : null);
    }

    /**
     * Test provided data
     * @param object $collection
     * @param object $content
     * @param object $test
     */
    protected function testData(object $collection, object $content, object $test): void
    {
        $mode = 0;

        if (!empty($test->modes) && is_array($test->modes)) {
            if (in_array('CAST', $test->modes)) {
                $mode ^= Validator::MODE_CAST;
            }

            if (in_array('REMOVE_ADDITIONALS', $test->modes)) {
                $mode ^= Validator::MODE_REMOVE_ADDITIONALS;
            }
        }

        $newData = null;
        $exception = null;

        try {
            $schema = new Schema($content->schema, $collection->version);
            $validator = new Validator($mode);
            $data = property_exists($test, 'data') ? $test->data : null;
            $newData = $validator->validate($data, $schema);
            $testResult = true;
        } catch (ValidationException $exception) {
            $testResult = false;
        } catch (Exception $exception) {
            $this->log(false, $collection->file, $content->description, $test->description, 'NON DATA EXCEPTION: ' . $exception->getMessage());
            return;
        }

        if (property_exists($test, 'expect')) {
            if (in_array(gettype($newData), ['object', 'array']) && in_array(gettype($test->expect), ['object', 'array'])) {
                if ($newData != $test->expect) {
                    $testResult = false;
                }
            } else {
                if ($newData !== $test->expect) {
                    $testResult = false;
                }
            }
        }

        $this->log($testResult === $test->valid, $collection->file, $content->description, $test->description, $exception ? $exception->getMessage() : null);
    }

    /**
     * Show results
     */
    public function showLog(): void
    {
        $totalValid = 0;
        $totalFail = 0;

        foreach ($this->log as $log) {
            if ($log->valid) {
                $totalValid++;
                continue;
            }

            // Build msg
            $msg = [];
            foreach (['file', 'contentDescription', 'testDescription', 'error'] as $property) {
                if ($log->{$property}) {
                    $msg[] = $log->{$property};
                }
            }
            $msg = implode(' / ', $msg);

            // Check ignore
            if (!$log->valid) {
                foreach ($this->ignores as $ignore) {
                    if (strstr($msg, $ignore) !== false) {
                        continue 2;
                    }
                }

                $totalFail++;
            }

            // Output
            $this->output($msg, $log->valid);
        }

        $this->output('Total Succeed: ' . $totalValid, true);
        $this->output('Total Fail: ' . $totalFail, false);

        if ($totalFail) {
            die(1);
        }
    }

    /**
     * Add log record
     * @param bool $valid
     * @param string $file
     * @param string $contentDescription
     * @param string|null $testDescription
     * @param string|null $error
     */
    protected function log(bool $valid, string $file, string $contentDescription, ?string $testDescription, ?string $error): void
    {
        $this->log[] = (object)[
            'valid' => $valid,
            'file' => $file,
            'contentDescription' => $contentDescription,
            'testDescription' => $testDescription,
            'error' => $error,
        ];
    }

    /**
     * Output text
     * @param string $msg
     * @param bool $valid
     */
    protected function output(string $msg, bool $valid): void
    {
        if (!$valid) {
            if (PHP_SAPI === 'cli') {
                print PHP_EOL . "\e[0;31;40m" . $msg . "!\e[0m" . PHP_EOL;
            } else {
                print '<pre style="color: red;">' . $msg . '</pre>';
            }
        } else {
            if (PHP_SAPI === 'cli') {
                print PHP_EOL . "\e[0;32;40m" . $msg . "!\e[0m" . PHP_EOL;
            } else {
                print '<pre style="color: #a3d39b;">' . $msg . '</pre>';
            }
        }
    }
}

// Start the test
$test = new Tests();
$test->addCollection(__DIR__ . '/draft7', '7');
$test->addCollection(__DIR__ . '/draft6', '6');

// PHP and big integer can`t validate two of the tests
$test->ignore('bignum.json / integer / a bignum is an integer / There is provided schema with type/s "integer" which not match with the data type "number"');
$test->ignore('bignum.json / integer / a negative bignum is an integer / There is provided schema with type/s "integer" which not match with the data type "number"');

// @todo - not ready yet
$test->ignore('properties.json / properties, patternProperties, additionalProperties interaction / patternProperty invalidates property');
$test->ignore('ref.json / Recursive references between schemas / valid tree / NON DATA EXCEPTION: Unknown reference "node"');
$test->ignore('ref.json / Recursive references between schemas / invalid tree / NON DATA EXCEPTION: Unknown reference "node"');
$test->ignore('ref.json / Location-independent identifier with base URI change in subschema / External reference download problem');
$test->ignore('ref.json / Location-independent identifier with base URI change in subschema / match / NON DATA EXCEPTION: External reference download problem');
$test->ignore('ref.json / Location-independent identifier with base URI change in subschema / mismatch / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / remote ref / External reference download problem');
$test->ignore('refRemote.json / remote ref / remote ref valid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / remote ref / remote ref invalid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / fragment within remote ref / External reference download problem');
$test->ignore('refRemote.json / fragment within remote ref / remote fragment valid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / fragment within remote ref / remote fragment invalid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / ref within remote ref / External reference download problem');
$test->ignore('refRemote.json / ref within remote ref / ref within ref valid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / ref within remote ref / ref within ref invalid / NON DATA EXCEPTION: External reference download problem');
$test->ignore('refRemote.json / base URI change / base URI change ref valid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / base URI change / base URI change ref invalid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / base URI change - change folder / number is valid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / base URI change - change folder / string is invalid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / base URI change - change folder in subschema / number is valid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / base URI change - change folder in subschema / string is invalid / NON DATA EXCEPTION: Unknown reference "folderInteger.json"');
$test->ignore('refRemote.json / root ref in remote ref / string is valid / NON DATA EXCEPTION: Unknown reference "name.json#/definitions/orNull"');
$test->ignore('refRemote.json / root ref in remote ref / null is valid / NON DATA EXCEPTION: Unknown reference "name.json#/definitions/orNull"');
$test->ignore('refRemote.json / root ref in remote ref / object is invalid / NON DATA EXCEPTION: Unknown reference "name.json#/definitions/orNull"');

// Run
$test->run();
