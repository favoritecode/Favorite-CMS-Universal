<?php
declare(strict_types=1);
namespace FavoriteCMS\Core;

/** Bounded-memory attachment delivery, with one byte range for resumable downloads. */
final class DownloadResponse extends Response
{
    private int $start = 0;
    private int $length;
    private bool $head;
    public function __construct(private string $path, string $filename, Request $request)
    {
        parent::__construct('',200);
        $size = (int)filesize($path); $this->length = $size; $this->head = $request->method() === 'HEAD';
        $filename = str_replace(["\r","\n","\0"], '', $filename);
        $ascii = preg_replace('/[^a-zA-Z0-9._ -]/','_', $filename) ?: 'download';
        $this->header('Content-Type','application/octet-stream')
            ->header('Content-Disposition','attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename))
            ->header('X-Content-Type-Options','nosniff')->header('Cache-Control','private, no-store')
            ->header('Accept-Ranges','bytes')->header('Content-Length',(string)$size);
        $range = $request->header('RANGE');
        if ($range !== '' && !$this->head && $request->header('IF_RANGE') === '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1] === '' && $m[2] === '') || $size === 0) { $this->unsatisfied($size); return; }
            if ($m[1] === '') { $suffix=(int)$m[2]; if($suffix<=0){$this->unsatisfied($size);return;} $start=max(0,$size-$suffix);$end=$size-1; }
            else { $start=(int)$m[1];$end=$m[2]!==''?min((int)$m[2],$size-1):$size-1; }
            if ($start >= $size || $end < $start) { $this->unsatisfied($size); return; }
            $this->start=$start;$this->length=$end-$start+1;
            $this->setStatus(206)->header('Content-Range',"bytes {$start}-{$end}/{$size}")->header('Content-Length',(string)$this->length);
        }
    }
    private function unsatisfied(int $size): void { $this->setStatus(416)->header('Content-Range','bytes */'.$size)->header('Content-Length','0');$this->length=0; }
    public function send(): void
    {
        if (!headers_sent()) { http_response_code($this->status); foreach($this->headers as $name=>$value) header($name.': '.$value); }
        if ($this->head || !$this->length) return;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $handle=fopen($this->path,'rb');if(!$handle)return;
        try { fseek($handle,$this->start);$remaining=$this->length;while($remaining>0&&!connection_aborted()){$chunk=fread($handle,min(1048576,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);} }
        finally { fclose($handle); }
    }
}
