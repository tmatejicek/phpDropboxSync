<?php

class DropboxSync
{

	var $oauth;
	
	var $revision = array();
	
	/**
	* Soubory které jsou aktualne na dropboxu
	*/
	var $overeneSoubory = array();
	
	function DropBoxSync(&$oauth)
	{
		$this->oauth = $oauth;
	}
	
	/**
	* Synchornizuje obsah složky na DropBoxu do lokálního umístění
	*/
	function synchronizuj($zdroj,$dst)
	{
		ini_set("max_execution_time", "3000");
		
		$revisionPath = $dst."/revision.txt";
		
		if(count($this->revision)==0 && file_exists($revisionPath))
			$this->revision = unserialize(file_get_contents($revisionPath));

		$this->me_synchronizuj($zdroj,$dst);
		
		// Odstraní soubory které byly smazány z DropBoxu
		$this->RemoveDeletedFiles($dst);
		
		// Odstraní zapomenuté soubory které nejsou v revizích (jen pro pířpad že nezafunguje správně RemoveDeletedFiles)
		$this->RemoveOldFiles($dst);
		
		// Odstraní prázdné složky
		$this->RemoveEmptySubFolders($dst);
		
		$revisionFile = fopen($revisionPath, 'w') or die("can't create revision file");
		fwrite ($revisionFile, serialize($this->revision));
		fclose ($revisionFile);
	}
	
	/**
	* Obsluha synchronizace
	*/
	function me_synchronizuj($zdroj,$dst)
	{
		if(isset($_SESSION["metaData"][$zdroj]))
		{
			$metadata = $_SESSION["metaData"][$zdroj];
		}
		else
		{
			$metadata = $this->getMetaData($zdroj);
			$_SESSION["metaData"][$zdroj] = $metadata;
		}
	
		if($metadata)
		{
			$metadata = json_decode($metadata, true);
			foreach($metadata["contents"] as $item)
			{
				if($item["is_dir"]==1 && substr(basename($item["path"]),0,1) !== ".") // create folder
				{
					$createFolder = "";
					foreach(explode("/",$item["path"]) as $folder)
					{
						if($folder!="")
						{
							$createFolder .= $folder."/";
							$tmpFolderPath = $dst."/".$createFolder;
							if(!file_exists($tmpFolderPath))
								mkdir($tmpFolderPath) or die("can't create folder");
						}
					}
					$this->me_synchronizuj($item["path"],$dst); // recursion
				}
				elseif($item["is_dir"]==0) // download file
				{
					$tmpFilePath = $dst.$item["path"];
					
					echo date("Y-m-d H:i:s"). substr((string)microtime(), 1, 6)." ".$tmpFilePath;
					
					if(substr(basename($item["path"]),0,1) == ".")
					{
						echo " - SKIP (HIDDEN FILE)";
					}
					elseif($item["bytes"] > 10000000)
					{
						echo " - SKIP (BIG FILE)";
					}
					elseif(!file_exists($tmpFilePath) || !isset($this->revision[$item["path"]]) || $this->revision[$item["path"]] != $item["revision"] || filesize($tmpFilePath)==0)
					{
						$file = fopen($tmpFilePath, 'w') or die("can't create file");
						$data = $this->getFile($item["path"]);
						fwrite ($file, $data);
						fclose ($file);
						echo " - DOWNLOAD[".$item["bytes"]." bytes] (".(isset($this->revision[$item["path"]])?$this->revision[$item["path"]]:0)." -> ".$item["revision"].")";
					}
					else
					{
						echo " - (".$this->revision[$item["path"]]." == ".$item["revision"].")";
					}
					$this->revision[$item["path"]] = $item["revision"];
					$this->overeneSoubory[] = $item["path"];
					echo "\n";
				}
		
				$revisionFile = fopen($dst."/revision.txt", 'w') or die("can't create revision file");
				fwrite ($revisionFile, serialize($this->revision));
				fclose ($revisionFile);
			}
		}
	}
	
	/**
	* Smaž lokální kopie soboru odstraněných z DropBoxu
	*/
	function RemoveDeletedFiles($dst)
	{
		foreach($this->revision as $soubor=>$revize)
		{
			if(!in_array($soubor,$this->overeneSoubory))
			{
				$souborMetadata = json_decode($this->getMetaData($soubor),true);
				if(isset($souborMetadata["is_deleted"]) && $souborMetadata["is_deleted"]==true)
				{
					unset($this->revision[$soubor]);
					@unlink($dst.$soubor);
					echo date("Y-m-d H:i:s"). substr((string)microtime(), 1, 6)." ".$dst.$soubor." - DELETED!\n";
		
					$revisionFile = fopen($dst."/revision.txt", 'w') or die("can't create revision file");
					fwrite ($revisionFile, serialize($this->revision));
					fclose ($revisionFile);
				}
			}
		}
	}
	
	/**
	* Smaže soubory které nemají revisi v DropBoxu
	*/
	function RemoveOldFiles($dst, $path="")
	{
		$path = $this->opravCestu($path);
		
		if ($handle = opendir($dst.$path))
		{
			while (false !== ($item = readdir($handle)))
			{
				if ($item != "." && $item != ".." && $item != "revision.txt")
				{
					if(is_dir($dst.$path.$item))
					{
						$this->RemoveOldFiles($dst, $path.$item);
					}
					else
					{
						if(!isset($this->revision[$path.$item]))
						{
							unlink($dst.$path.$item);
							echo date("Y-m-d H:i:s"). substr((string)microtime(), 1, 6)." ".$dst.$path.$item." - DELETED!\n";
						}
					}
				}
			}
			closedir($handle);
		}
	}
	
	/**
	* Smaže prázdné adresáře
	*/
	function RemoveEmptySubFolders($path)
	{
		$empty = true;
		foreach (glob($path.DIRECTORY_SEPARATOR."*") as $file)
		{
			if (is_dir($file))
			{
				if (!$this->RemoveEmptySubFolders($file))
					$empty = false;
			}
			else
			{
				$empty = false;
			}
		}
		
		if ($empty)
			rmdir($path);
			
		return $empty;
	}
	
	/**
	* Doplní na konec cesty lomítko, kdž je třeba
	*/
	function opravCestu($cesta)
	{
		if (substr($cesta,-1)<>"/")
			$cesta .= "/";
			
		return $cesta;
	}
	
	/**
	* Returns file and directory information
	* 
	* @param string $path Path to receive information from 
	* @param bool $list When set to true, this method returns information from all files in a directory. When set to false it will only return infromation from the specified directory.
	* @return array|true 
	*/
    function getMetaData($path, $list = true)
    {
        $path = implode("/", array_map('rawurlencode', explode("/", $path)));
        return $this->oauth->fetch('https://api.dropbox.com/1/metadata/dropbox/' . ltrim($path,'/'), array('list' => $list));
    }
    
    /**
	* Returns a file's contents 
	* 
	* @param string $path path
	* @return string 
	*/
    function getFile($path = '')
    {
        $path = implode("/", array_map('rawurlencode', explode("/", $path)));
        return $this->oauth->fetch('https://api-content.dropbox.com/1/files/dropbox/' . ltrim($path,'/'));
    }
    
    /**
    * TODO !!! NOT TESTED!!!
    */
    function upload($filename, $remoteDir='/')
    {
        if (!file_exists($filename) or !is_file($filename) or !is_readable($filename))
        {
            die("File '$filename' does not exist or is not readable.");
        }
        
        $data = $this->oauth->fetch('https://api-content.dropbox.com/0/files/dropbox/' . ltrim($remoteDir,'/'), array('file'=>$filename, 'parent_rev'=>$this->revision[$remoteDir.$filename]), true);

        if (strpos($data, 'HTTP/1.1 302 FOUND') === false)
        {
            die('Upload failed!');
        }
            
        return true;
    }
}

function unicode_decode($string)
{
	return preg_replace("/\\\u([0-9A-F]{4})/ie", "chr(base_convert(\"$1\",16,10))", $string);
}

if (!function_exists('json_decode')) {
	function json_decode($json, $forCompatibility)
	{
		$comment = false;
		$out = '$x=';
	  
		for ($i=0; $i<strlen($json); $i++)
		{
			if (!$comment)
			{
				if (($json[$i] == '{') || ($json[$i] == '['))       $out .= ' array(';
				else if (($json[$i] == '}') || ($json[$i] == ']'))   $out .= ')';
				else if ($json[$i] == ':')    $out .= '=>';
				else                         $out .= $json[$i];          
			}
			else $out .= $json[$i];
			if ($json[$i] == '"' && $json[($i-1)]!="\\")    $comment = !$comment;
		}
		eval($out . ';');
		return $x;
	}
}