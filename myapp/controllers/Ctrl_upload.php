<?php
// NOTE: This code is derived from the code found in valums-file-uploader-b3b20b1/server/php.php.
// Please refer to the copyright and license for the valums-file-uploader-b3b20b1 package.


/**
 * Handle file uploads via XMLHttpRequest
 */
class qqUploadedFileXhr {
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {    
        $input = fopen("php://input", "r");
        $temp = tmpfile();
        $realSize = stream_copy_to_stream($input, $temp);
        fclose($input);
        
        if ($realSize != $this->getSize()){            
            return false;
        }
        
        $target = fopen($path, "w");        
        fseek($temp, 0, SEEK_SET);
        stream_copy_to_stream($temp, $target);
        fclose($target);
        
        return true;
    }
    function getName() {
        return $_GET['qqfile'];
    }
    function getSize() {
        if (isset($_SERVER["CONTENT_LENGTH"])){
            return (int)$_SERVER["CONTENT_LENGTH"];            
        } else {
            throw new Exception('Getting content length is not supported.');
        }      
    }   
}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qqUploadedFileForm {  
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {
        if(!move_uploaded_file($_FILES['qqfile']['tmp_name'], $path)){
            return false;
        }
        return true;
    }
    function getName() {
        return $_FILES['qqfile']['name'];
    }
    function getSize() {
        return $_FILES['qqfile']['size'];
    }
}

class qqFileUploader {
    private $allowedExtensions = array();
    private $sizeLimit = 10485760;
    private $file;

    function __construct(array $allowedExtensions = array(), $sizeLimit = 10485760){        
        $allowedExtensions = array_map("strtolower", $allowedExtensions);
            
        $this->allowedExtensions = $allowedExtensions;        
        $this->sizeLimit = $sizeLimit;
        
        $this->checkServerSettings();       

        if (isset($_GET['qqfile'])) {
            $this->file = new qqUploadedFileXhr();
        } elseif (isset($_FILES['qqfile'])) {
            $this->file = new qqUploadedFileForm();
        } else {
            $this->file = false; 
        }
    }
    
    private function checkServerSettings(){        
        $postSize = $this->toBytes(ini_get('post_max_size'));
        $uploadSize = $this->toBytes(ini_get('upload_max_filesize'));        
        
        if ($postSize < $this->sizeLimit || $uploadSize < $this->sizeLimit){
            $size = max(1, $this->sizeLimit / 1024 / 1024) . 'M';             
            die("{'error':'increase post_max_size and upload_max_filesize to $size'}");    
        }        
    }
    
    private function toBytes($str){
        $val = trim($str);
        $last = strtolower($str[strlen($str)-1]);
        switch($last) {
          case 'g': $val = substr($val,0,-1) * 1024 * 1024 * 1024; break;
          case 'm': $val = substr($val,0,-1) * 1024 * 1024; break;
          case 'k': $val = substr($val,0,-1) * 1024; break;
        }
        return $val;
    }
    
    /**
     * Returns array('success'=>true) or array('error'=>'error message')
     */
    function handleUpload($uploadDirectory, $replaceOldFile, $mod_quizpath, $owner) {
        if (!is_writable("quizzes/$uploadDirectory")){
            return array('error' => "Server error. Upload directory isn't writable.");
        }
        
        if (!$this->file){
            return array('error' => 'No files were uploaded.');
        }
        
        $size = $this->file->getSize();
        
        if ($size == 0) {
            return array('error' => 'File is empty');
        }
        
        if ($size > $this->sizeLimit) {
            return array('error' => 'File is too large');
        }
        
        $pathinfo = pathinfo($this->file->getName());
        $filename = $pathinfo['filename'];
        //$filename = md5(uniqid());
        $ext = $pathinfo['extension'];

        if($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)){
            $these = implode(', ', $this->allowedExtensions);
            return array('error' => 'File has an invalid extension, it should be one of '. $these . '.');
        }
        
        if(!$replaceOldFile){
            /// don't overwrite previous files that were uploaded

            if (file_exists("quizzes/$uploadDirectory/$filename.$ext")) {
                $num = 0;
                do {
                    ++$num;
                } while (file_exists("quizzes/$uploadDirectory/{$filename}_$num.$ext"));
                $filename .= "_$num";
            }
        }
        
        if ($this->file->save("quizzes/$uploadDirectory/$filename.$ext")){
            $mod_quizpath->init($uploadDirectory, true, false);
			$mod_quizpath->set_owner($owner, "$filename.$ext");

            return array('success'=>true);
        } else {
            return array('error'=> 'Could not save uploaded file.' .
                'The upload was cancelled, or server error encountered');
        }
        
    }    
}




class Ctrl_upload extends MY_Controller {
    public function __construct() {
        parent::__construct();
    }

	public function index() {
        $this->load->model('mod_quizpath');

        // list of valid extensions, ex. array("jpeg", "xml", "bmp")
        $allowedExtensions = array();
        // max file size in bytes
        $sizeLimit = 10 * 1024 * 1024;

        $uploader = new qqFileUploader($allowedExtensions, $sizeLimit);
        $result = $uploader->handleUpload($_GET['dir'], false, $this->mod_quizpath, $this->mod_users->my_id());
        // to pass data through iframe you will need to encode all html tags
        echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);
	}
}
