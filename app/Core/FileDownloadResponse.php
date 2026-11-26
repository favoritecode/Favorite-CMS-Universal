<?php
declare(strict_types=1);
namespace FavoriteCMS\Core;
/** Streams an already-open private file; never loads an archive/dump into PHP memory. */
final class FileDownloadResponse extends Response {
 private mixed $stream;
 public function __construct(private string $path,string $filename,string $mime,private bool $deleteAfter=false){
  $this->stream=fopen($path,'rb');if($this->stream===false)throw new \RuntimeException('Download file could not be opened.');$stat=fstat($this->stream);
  parent::__construct('',200,['Content-Type'=>$mime,'Content-Disposition'=>'attachment; filename="'.str_replace(['"',"\r","\n"],'',$filename).'"','Content-Length'=>(string)$stat['size'],'Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']);
 }
 public function send():void{try{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();parent::send();if(fpassthru($this->stream)===false)throw new \RuntimeException('Download could not finish.');}finally{$this->close();}}
 private function close():void{if(is_resource($this->stream))fclose($this->stream);$this->stream=null;if($this->deleteAfter&&is_file($this->path))@unlink($this->path);}
 public function __destruct(){$this->close();}
}
