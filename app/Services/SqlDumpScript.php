<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
/** MySQL script reader: DELIMITER, quoted strings/identifiers and comments are respected. */
final class SqlDumpScript {
 static function statements(string $path):\Generator{
  $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('SQL file could not be read.');$buffer='';$quote='';$block=false;$executable=false;$delimiter=';';
  try{while(($line=fgets($h))!==false){
   if($quote===''&&!$block&&trim($buffer)===''&&preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$m)){$delimiter=$m[1];continue;}
   for($i=0,$n=strlen($line);$i<$n;$i++){$c=$line[$i];$next=$line[$i+1]??'';
    if($block){if($executable)$buffer.=$c;if($c==='*'&&$next==='/'){if($executable)$buffer.='/';$i++;$block=false;$buffer.=' ';}continue;}
    if($quote!==''){$buffer.=$c;if($c==='\\'&&$i+1<$n){$buffer.=$line[++$i];continue;}if($c===$quote){if($next===$quote){$buffer.=$line[++$i];}else $quote='';}continue;}
    if($c==='#'||$c==='-'&&$next==='-'&&($i+2===$n||ctype_space($line[$i+2]))){$buffer.="\n";break;}
    if($c==='/'&&$next==='*'){$block=true;$executable=($line[$i+2]??'')==='!';if($executable)$buffer.='/*';$i++;continue;}
    if(in_array($c,["'",'"','`'],true)){$quote=$c;$buffer.=$c;continue;}
    if(substr($line,$i,strlen($delimiter))===$delimiter){$sql=trim($buffer);$buffer='';$i+=strlen($delimiter)-1;if($sql!=='')yield $sql;continue;}
    $buffer.=$c;
   }
  }if($quote!==''||$block)throw new \RuntimeException('SQL dump contains an incomplete quoted statement.');if(trim($buffer)!=='')yield trim($buffer);
  }finally{fclose($h);}
 }
 /** Rewrite identifiers only, never text/JSON/password literals. */
 static function identifiers(string $sql,array $map=[],?string $stripSchema=null):string{
  $out='';$n=strlen($sql);
  for($i=0;$i<$n;){$c=$sql[$i];
   if($c==="'"||$c==='"'){$q=$c;$out.=$c;$i++;while($i<$n){$x=$sql[$i++];$out.=$x;if($x==='\\'&&$i<$n){$out.=$sql[$i++];continue;}if($x===$q){if(($sql[$i]??'')===$q){$out.=$sql[$i++];continue;}break;}}continue;}
   if(substr($sql,$i,2)==='/*'){$end=strpos($sql,'*/',$i+2);if($end===false)throw new \RuntimeException('Invalid SQL comment.');$out.=substr($sql,$i,$end+2-$i);$i=$end+2;continue;}
   if($c==='`'){$i++;$name='';while($i<$n){$x=$sql[$i++];if($x==='`'){if(($sql[$i]??'')==='`'){$name.='`';$i++;continue;}break;}$name.=$x;}$j=$i;while($j<$n&&ctype_space($sql[$j]))$j++;if($stripSchema!==null&&$name===$stripSchema&&($sql[$j]??'')==='.'){$i=$j+1;continue;}$out.='`'.str_replace('`','``',$map[$name]??$name).'`';continue;}
   if(ctype_alpha($c)||$c==='_'){$start=$i++;while($i<$n&&(ctype_alnum($sql[$i])||$sql[$i]==='_'))$i++;$name=substr($sql,$start,$i-$start);$out.=$map[$name]??$name;continue;}
   $out.=$c;$i++;
  }return $out;
 }
}
