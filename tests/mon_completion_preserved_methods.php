<?php
declare(strict_types=1);
require $argv[1];$out=[];
$classes=['MonCollector','MonDaily','MonDetailWorker','MonManualStart','MonDaemonLoad','MonSwing','MonRun'];
foreach($classes as $name){$class=new ReflectionClass($name);$lines=file($class->getFileName());foreach($class->getMethods()as $method){
    if($name==='MonSwing'&&in_array($method->getName(),['migrate','selectData'],true))continue;
    if($name==='MonRun'&&!in_array($method->getName(),['current','quoteState','periods','verifyFacts'],true))continue;
    $raw=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));$out[$name.'::'.$method->getName()]=hash('sha256',$raw);
}}ksort($out);echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
