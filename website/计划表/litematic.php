<?php
declare(strict_types=1);

final class PlanNbtReader
{
    private int $offset=0;
    public function __construct(private string $data){}
    private function take(int $n):string{if($this->offset+$n>strlen($this->data))throw new RuntimeException('NBT 文件不完整');$v=substr($this->data,$this->offset,$n);$this->offset+=$n;return $v;}
    private function byte():int{$v=ord($this->take(1));return $v>127?$v-256:$v;}
    private function ubyte():int{return ord($this->take(1));}
    private function short():int{$v=unpack('n',$this->take(2))[1];return $v>32767?$v-65536:$v;}
    private function int():int{$v=unpack('N',$this->take(4))[1];return $v>2147483647?$v-4294967296:$v;}
    private function long():int{return unpack('J',$this->take(8))[1];}
    private function string():string{$n=$this->short();if($n<0)throw new RuntimeException('NBT 字符串长度无效');return $this->take($n);}
    private function tag(int $type):mixed{return match($type){1=>$this->byte(),2=>$this->short(),3=>$this->int(),4=>$this->long(),5=>unpack('G',$this->take(4))[1],6=>unpack('E',$this->take(8))[1],7=>$this->byteArray(),8=>$this->string(),9=>$this->list(),10=>$this->compound(),11=>$this->intArray(),12=>$this->longArray(),default=>throw new RuntimeException('未知 NBT 标签: '.$type)};}
    private function byteArray():array{$n=$this->int();if($n<0||$n>100000000)throw new RuntimeException('NBT 数组过大');return array_values(unpack('c*',$this->take($n)));}
    private function intArray():array{$n=$this->int();if($n<0||$n>25000000)throw new RuntimeException('NBT 数组过大');$out=[];for($i=0;$i<$n;$i++)$out[]=$this->int();return $out;}
    private function longArray():array{$n=$this->int();if($n<0||$n>12500000)throw new RuntimeException('NBT 数组过大');$out=[];for($i=0;$i<$n;$i++)$out[]=$this->long();return $out;}
    private function list():array{$type=$this->ubyte();$n=$this->int();if($n<0||$n>10000000)throw new RuntimeException('NBT 列表过大');$out=[];for($i=0;$i<$n;$i++)$out[]=$this->tag($type);return $out;}
    private function compound():array{$out=[];while(true){$type=$this->ubyte();if($type===0)break;$out[$this->string()]=$this->tag($type);}return $out;}
    public function root():array{if($this->ubyte()!==10)throw new RuntimeException('NBT 根标签不是 Compound');$this->string();return $this->compound();}
}

function plan_parse_litematic(string $path):array
{
    $compressed=file_get_contents($path);if($compressed===false)throw new RuntimeException('无法读取上传文件');
    $raw=@gzdecode($compressed);if($raw===false)throw new RuntimeException('文件不是有效的 gzip Litematica 投影');
    if(strlen($raw)>128*1024*1024)throw new RuntimeException('投影解压后超过 128MB 限制');
    $root=(new PlanNbtReader($raw))->root();$regions=$root['Regions']??null;
    if(!is_array($regions)||!$regions)throw new RuntimeException('投影中没有 Regions 数据');
    $counts=[];
    foreach($regions as $region){
        if(!is_array($region))continue;$palette=$region['BlockStatePalette']??[];$states=$region['BlockStates']??[];$size=$region['Size']??[];
        if(!is_array($palette)||!is_array($states)||!is_array($size)||!$palette)continue;
        $total=abs((int)($size['x']??0))*abs((int)($size['y']??0))*abs((int)($size['z']??0));
        if($total>50000000)throw new RuntimeException('单个投影区域超过 5000 万方块限制');
        $bits=max(2,(int)ceil(log(max(1,count($palette)),2)));$mask=(1<<$bits)-1;$bit=0;
        for($i=0;$i<$total;$i++){
            $word=intdiv($bit,64);$shift=$bit%64;
            $value=(($states[$word]??0)>>$shift)&$mask;
            if($shift+$bits>64){$low=64-$shift;$value|=(($states[$word+1]??0)&((1<<($bits-$low))-1))<<$low;}
            $bit+=$bits;$state=is_array($palette[$value]??null)?$palette[$value]:[];$name=(string)($state['Name']??'minecraft:air');
            if($name!=='minecraft:air')$counts[$name]=($counts[$name]??0)+1;
            $properties=is_array($state['Properties']??null)?$state['Properties']:[];
            if(($properties['waterlogged']??null)==='true')$counts['minecraft:water']=($counts['minecraft:water']??0)+1;
        }
    }
    foreach(['water'=>'water_bucket','lava'=>'lava_bucket'] as $from=>$to){$key='minecraft:'.$from;if(isset($counts[$key])){$target='minecraft:'.$to;$counts[$target]=($counts[$target]??0)+$counts[$key];unset($counts[$key]);}}
    arsort($counts);$out=[];foreach($counts as $id=>$count)$out[]=['blockId'=>$id,'displayName'=>plan_block_name($id),'count'=>$count,'boxes'=>$count/1728,'stacks'=>$count/64];
    return $out;
}

function plan_block_name(string $id):string
{
    static $translations=null;
    if($translations===null){$file=__DIR__.'/translations.json';$data=is_file($file)?json_decode((string)file_get_contents($file),true):[];$translations=is_array($data['blocks']??null)?$data['blocks']:[];}
    $key=str_replace('minecraft:','',$id);
    return (string)($translations[$id]??$translations[$key]??ucwords(str_replace('_',' ',$key)));
}
