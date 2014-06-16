<?php
ini_set('memory_limit', '-1');
require_once './Baidu-PCS-SDK-PHP-L2-2.1.0/libs/BaiduPCS.class.php';

class sync
{
    const APP_NAME = 'pan-sync';
    const REMOTE_ROOT = '/apps/pan-sync';

    protected $refresh_token;
    protected $access_token;
    protected $sync_path;
    protected $remote_dir;

    protected $pcs;

    protected $counter;

    public function __construct($refresh_token, $access_token, $sync_path, $remote_dir)
    {
        $this->refresh_token = $refresh_token;
        $this->access_token = $access_token;
        $this->sync_path = $sync_path;
        $this->remote_dir = $remote_dir;

        $this->pcs = new BaiduPCS($access_token);

        $this->counter = 0;
    }

    protected function uploadFile($filename, $dir, $remoteDir)
    {
        //$blockSize = 1932735283;
        $blockSize = 100000000;
        $fileSize = filesize($dir . '/' . $filename);

        if ($fileSize == 0) {
            return;
        }

        echo '......uploading file ' . $dir . '/' . $filename . '    ' . ++$this->counter . "\n";

        $handle = fopen($dir . '/' . $filename, 'rb');
        $result = NULL;

        try {
            if ($fileSize < $blockSize) {
                $fileContent = fread($handle, $fileSize);
                $result = $this->pcs->upload($fileContent, $remoteDir . '/', $filename);
            } else {
                $filesBlock = array();

                while (!feof($handle)) {
                    $temp = $this->pcs->upload(fread($handle, $blockSize), $remoteDir . '/', $filename, $filename, TRUE);
                    if (!is_array($temp)) {
                        $temp = json_decode($temp, true);
                    }
                    print_r($temp);
                    echo "\n";
                    array_push($filesBlock, $temp);
                }

                if (count($filesBlock) > 1) {
                    $params = array();
                    foreach ($filesBlock as $value) {
                        array_push($params, $value['md5']);
                    }
                    $result = $this->pcs->createSuperFile($remoteDir . '/', $filename, $params);
                }
            }
        } catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
        }

        fclose($handle);

        print_r($result);
        echo "\n";
    }

    protected function syncDir($dir, $remoteDir, $force = FALSE, $existingDir = FALSE)
    {
        echo '......scanning directory ' . $dir . ' force=' . $force . "\n";

        $files = array();
        $dirs = array();

        $items = scandir($dir);
        foreach ($items as $item) {
            if (in_array($item, array('.', '..'))) {
                continue;
            }

            if (is_dir($dir . DIRECTORY_SEPARATOR . $item)) {
                $dirs[] = $item;
            } else {
                $files[] = $item;
            }
        }

        $paths = array();
        $dirPaths = array();

        if ($force) {
            $result = $this->pcs->makeDirectory($remoteDir);
        } else {
            if (!$existingDir) {
                $result = $this->pcs->getMeta($remoteDir);
                $result = json_decode($result);

                if (isset($result->error_code) && $result->error_code == '31066') {
                    $this->syncDir($dir, $remoteDir, TRUE);

                    return;
                }
            }

            $result = $this->pcs->listFiles($remoteDir);
            $result = json_decode($result);

            if (!isset($result->list) || !is_array($result->list)) {
                throw new Exception('invalid reponse');
            }

            foreach ($result->list as $obj) {
                if ($obj->isdir == '1') {
                    $dirPaths[$obj->path] = $obj->size;
                } else {
                    $paths[$obj->path] = $obj->size;
                }
            }
        }

        foreach ($files as $file) {
            if (!$force && array_key_exists($remoteDir . '/' . $file, $paths)) {
                if (filesize($dir . '/' . $file) == $paths[$remoteDir . '/' . $file]) {
                    continue;
                } else {
                    echo 'WARNING: file size not matched: ' . $dir . '/' . $file . "\n";
                }
            }

            $this->uploadFile($file, $dir, $remoteDir);
        }

        foreach ($dirs as $item) {
            $this->syncDir($dir . '/' . $item, $remoteDir . '/' . $item, $force, array_key_exists($remoteDir . '/' . $item, $dirPaths));
        }
    }

    public function start()
    {
        $this->syncDir($this->sync_path, self::REMOTE_ROOT . '/' . $this->remote_dir);
    }
}

if (count($argv) == 5) {

    $refresh_token = $argv[1];
    $access_token = $argv[2];
    $sync_path = $argv[3];
    $remote_dir = $argv[4];

} else {

    $refresh_token = readline('Step 1. Input refresh_token: ');
    $access_token = readline('Step 2. Input access_token: ');
    $sync_path = readline('Step 3. Path to sync: ');
    $remote_dir = readline('Step 4. Remote directory name: ');

}

//$refresh_token = 'a';
//$access_token = '';
//$sync_path = '/Users/hzhao/tmp/testbp';
//$remote_dir = 'api_test';

echo "Step 5. Start syncing...\n";
$syncObj = new sync($refresh_token, $access_token, $sync_path, $remote_dir);
$syncObj->start();
