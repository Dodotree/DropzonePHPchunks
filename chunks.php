<?php

    /**
     * @author Gregory Chris (http://online-php.com)
     * @email www.online.php@gmail.com
     * @editor Bivek Joshi (http://www.bivekjoshi.com.np)
     * @email meetbivek@gmail.com
     * HEAVILY MODIFIED by Veny T
     * This is a plain PHP version for dropzone
     * Chunks collected in uploads/tmp/
     * Whole files placed in uploads/whole_from_chunks/
     * Name of the final file returned upon finished process
     */
     
     
    function returnJson($arr){
        header('Content-type: application/json');
        print json_encode($arr);
        exit;
    }

     
   if (!empty($_FILES)){
        foreach ($_FILES as $file) {
            if ($file['error'] != 0) {
                $errors[] = array( 'text'=>'File error', 'error'=>$file['error'], 'name'=>$file['name']);
                continue;
            }
            if(!$file['tmp_name']){
                $errors[] = array( 'text'=>'Tmp file not found', 'name'=>$file['name']);
                continue;
            }

            $tmp_file_path = $file['tmp_name'];
            $filename =  (isset($file['filename']) )? $file['filename'] : $file['name'];

            if( isset($_POST['dzuuid'])){
                $chunks_res = resumableUpload($tmp_file_path, $filename);
                if(!$chunks_res['final']){
                    returnJson( $chunks_res );
                }
                $tmp_file_path = $chunks_res['path'];
            }
         }
     }

    /**
     *
     * Delete a directory RECURSIVELY
     * @param string $dir - directory path
     * @link http://php.net/manual/en/function.rmdir.php
     */
    function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype("$dir/$object") == "dir") {
                        rrmdir("$dir/$object");
                    } else {
                        unlink("$dir/$object");
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }


    function cleanUp($file_chunks_folder){
        // rename the temporary directory (to avoid access from other concurrent chunks uploads) and than delete it
        if (rename($file_chunks_folder, $file_chunks_folder.'_UNUSED')) {
            rrmdir($file_chunks_folder.'_UNUSED');
        } else {
            rrmdir($file_chunks_folder);
        }
    }

    function resumableUpload($tmp_file_path, $filename){
        $successes = array();
        $errors = array();
        $warnings = array();
        $dir = "functions/uploads/tmp/";

            $identifier = ( isset($_POST['dzuuid']) )?  trim($_POST['dzuuid']) : '';

            $file_chunks_folder = "$dir$identifier";
            if (!is_dir($file_chunks_folder)) {
                mkdir($file_chunks_folder, 0777, true);
            }

            $filename = str_replace( array(' ','(', ')' ), '_', $filename ); # remove problematic symbols
            $info = pathinfo($filename);
            $extension = isset($info['extension'])? '.'.strtolower($info['extension']) : '';
            $filename = $info['filename'];

            $totalSize =   (isset($_POST['dztotalfilesize']) )?    (int)$_POST['dztotalfilesize'] : 0;
            $totalChunks = (isset($_POST['dztotalchunkcount']) )?  (int)$_POST['dztotalchunkcount'] : 0;
            $chunkInd =  (isset($_POST['dzchunkindex']) )?         (int)$_POST['dzchunkindex'] : 0;
            $chunkSize = (isset($_POST['dzchunksize']) )?          (int)$_POST['dzchunksize'] : 0;
            $startByte = (isset($_POST['dzchunkbyteoffset']) )?    (int)$_POST['dzchunkbyteoffset'] : 0;

            $chunk_file = "$file_chunks_folder/{$filename}.part{$chunkInd}";

            if (!move_uploaded_file($tmp_file_path, $chunk_file)) {
                $errors[] = array( 'text'=>'Move error', 'name'=>$filename, 'index'=>$chunkInd );
            }

            if( count($errors) == 0 and $new_path = checkAllParts(  $file_chunks_folder,
                                                                    $filename,
                                                                    $extension,
                                                                    $totalSize,
                                                                    $totalChunks,
                                                                    $successes, $errors, $warnings) and count($errors) == 0){
                return array('final'=>true, 'path'=>$new_path, 'successes'=>$successes, 'errors'=>$errors, 'warnings' =>$warnings);
            }
    return array('final'=>false, 'successes'=>$successes, 'errors'=>$errors, 'warnings' =>$warnings);
    }


    function checkAllParts( $file_chunks_folder,
                            $filename,
                            $extension,
                            $totalSize,
                            $totalChunks,
                            &$successes, &$errors, &$warnings){

        // reality: count all the parts of this file
        $parts = glob("$file_chunks_folder/*");
        $successes[] = count($parts)." of $totalChunks parts done so far in $file_chunks_folder";

        // check if all the parts present, and create the final destination file
        if( count($parts) == $totalChunks ){
            $loaded_size = 0;
            foreach($parts as $file) {
                $loaded_size += filesize($file);
            }
            if ($loaded_size >= $totalSize and $new_path = createFileFromChunks(
                                                            $file_chunks_folder,
                                                            $filename,
                                                            $extension,
                                                            $totalSize,
                                                            $totalChunks,
                                                            $successes, $errors, $warnings) and count($errors) == 0){
                cleanUp($file_chunks_folder);
                return $new_path;
            }
        }
    return false;
    }


    /**
     * Check if all the parts exist, and
     * gather all the parts of the file together
     * @param string $file_chunks_folder - the temporary directory holding all the parts of the file
     * @param string $fileName - the original file name
     * @param string $totalSize - original file size (in bytes)
     */
    function createFileFromChunks($file_chunks_folder, $fileName, $extension, $total_size, $total_chunks,
                                            &$successes, &$errors, &$warnings) {

        $rel_path = "functions/uploads/whole_from_chunks/";
        $saveName = getNextAvailableFilename( $rel_path, $fileName, $extension, $errors );

        if( !$saveName ){
            return false;
        }

        $fp = fopen("$rel_path$saveName$extension", 'w');
        if ($fp === false) {
            $errors[] = 'cannot create the destination file';
            return false;
        }
        for ($i=0; $i<$total_chunks; $i++) {
            fwrite($fp, file_get_contents($file_chunks_folder.'/'.$fileName.'.part'.$i));
        }
        fclose($fp);

        return "$rel_path$saveName$extension";
    }


    function getNextAvailableFilename( $rel_path, $orig_file_name, $extension, &$errors ){
        if( file_exists("$rel_path$orig_file_name$extension") ){
            $i=0;
            while(file_exists("$rel_path{$orig_file_name}_".(++$i).$extension) and $i<10000){}
            if( $i >= 10000 ){
                $errors[] = "Can not create unique name for saving file $orig_file_name$extension";
                return false;
            }
        return $orig_file_name."_".$i;
        }
    return $orig_file_name;
    }

