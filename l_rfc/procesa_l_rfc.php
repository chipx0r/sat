<?php
##############################################################
# procesa_l_rfc.php                                          #
#                                                            #
# 1 Descarga ultimos archivos l_rfc publicados por el SAT    #
# 2 Los descomprima y valida que el sello openssl sea valido #
# 3 Los carga en la base de datos para poder validar los XML #
##############################################################
error_reporting(E_ALL);
ini_set("date.timezone","America/Mexico_city");
chdir("/home/project-web/cfdcvali/htdocs/tmp");
system("rm -f con_* sin_*");
$ok = descarga_rfc();
if ($ok) {
    procesa_txt();
    if ($ok) {
        carga_l_rfc();
    }
}

function descarga_rfc() {
    echo "Inicia descarga ".date("c")."\n";
    $ok = true;
    $fech = date("Y");
    $url = "https://cfdisat.blob.core.windows.net/lco?restype=container&comp=list&prefix=l_RFC_$fech";
    echo "$url\n";
    $opts = array('http' => array('method'=>'GET', 
                              'timeout' => 5,
                              'protocol_version' => 1.1,
                              'header' => 'Connection: close'
                          )
             );
    $ctx = stream_context_create($opts);
    $xmltxt = file_get_contents($url,false,$ctx);
    file_put_contents("lista.xml",$xmltxt);
    $xml = new DOMDocument("1.0","UTF-8");
    $xml->loadXML($xmltxt);
    $max="l_RFC_0000_00_00";
    $Blob = $xml->getElementsByTagName('Blob');
    foreach ($Blob as $file) { // Para buscar la ultima fecha publicada
        $part = substr(leenodo($file,"Name"),0,16);
        if ($part>$max) $max=$part;
        // echo "part=$part max=$max\n";
    }
    $ultimo = file_get_contents("ultimo.txt");
    echo "Ultimo dia publicado $max, ultimo previamente procesado $ultimo\n";
    if ($ultimo == $max) die("Ya procesado, no continua\n");
    file_put_contents("ultimo.txt",$max);
    // TODO : Comparar contra la ultima fecha procesada, 
    //        para no volver a procesar lo mismo
    $Blob = $xml->getElementsByTagName('Blob');
    foreach ($Blob as $file) {
        $name = leenodo($file,"Name");
        $part = substr($name,0,16);
        if ($part == $max) {
            $url = leenodo($file,"Url");
            $md5 = leenodo($file,"Content-MD5");
            echo "\n----------------\nnombre=$name\nurl=$url\nhash=$md5\n\n";
            $ok = leeurl($name, $url, $md5);
            if (!$ok) {
                $ok = false;
                return $ok;
            }
        }
    }
    echo "Termina descarga ".date("c")."\n";
    return $ok;
}


function procesa_txt() {
    echo "Inicia gunzip y validacion openssl\n";
    $ok=true;
    system("rm -f con_*.txt sin_*.txt");
    for ($parte=1; $parte<=7; $parte++) {
        $file="con_${parte}.txt";
        if ( file_exists("${file}.gz")) {
            echo "$file\n";
            system("gunzip ${file}.gz");
            $ret=system("openssl smime -verify -in $file -inform der -noverify -out sin_${parte}.txt");
            if ($ret != 0) {
                $ok=false;
                break;
            }
        }
    }
    echo ($ok)? "listo" : "fallo, no sigue";
    echo "\n";
    return $ok;
}

function carga_l_rfc() {
    echo "Inicia base de datos ".date("c")."\n";
    require_once('../myconn/myconn.inc.php');
    $conn = myconn();   
    $conn->debug=true;
    $conn->execute("drop table temp_l_rfc");
    $conn->execute("create table temp_l_rfc (rfc_rfc char(13), rfc_sncf char(2), rfc_sub char(2)) ");
    if ($conn->dataProvider=="mysql") {
        $conn->execute("SET autocommit=0");
        $handle = $conn->prepare("insert into temp_l_rfc values (?,?,?)");
    } elseif ($conn->dataProvider=="postgres") {
        $pg=$conn->_connectionID; // Directa a postgresql para COPY rapido
        pg_query($pg, "copy temp_l_rfc from stdin with delimiter '|' null as ''");
    }
    $conn->debug=false;
    $cant=0;
    $archivos = array("sin_1.txt","sin_2.txt","sin_3.txt","sin_4.txt",
                      "sin_5.txt","sin_6.txt","sin_7.txt");
    foreach ($archivos as $file) {
        $gestor = @fopen($file, "r");
        if ($gestor) {
           echo "$file";
           $primero=true;
           while (!feof($gestor)) {
               $buffer = fgets($gestor, 4096);
               if ($cant%10000==0) echo ".";
               if ($primero) {
                   # Se ignora el primer registro porque son los encabezados
                   $primero=false;
               } else {
                   $buffer=trim($buffer);
                   // Yo me base en el primero ejemplo y es char si/no
                   $l=strlen($buffer);
                   if ($l>=5) {
                       $cant++;
                       if ($conn->dataProvider=="mysql") {
                           list($rfc,$sncf,$sub) = explode("|",$buffer);
                           $sncf=($sncf=="1")?"si":"no";
                           $sub=($sub=="1")?"si":"no";
                           $prm=array($rfc,$sncf,$sub);
                           $conn->execute($handle,$prm);
                       } elseif ($conn->dataProvider=="postgres") {
                           $buffer=str_replace("|0","|no",$buffer);
                           $buffer=str_replace("|1","|si",$buffer);
                           if (substr($buffer,-1)=="|")
                               $buffer=substr($buffer,0,$l-1);
                           pg_put_line($pg, $buffer."\n");
                       }
                   } // size 5
               } // Primero
           } // While cada registro
           fclose ($gestor);
           echo "\n";
        } // gestor del archivo
    } // while cada archivo en el directorio
   if ($conn->dataProvider=="mysql") {
       $conn->execute("COMMIT");
   } elseif ($conn->dataProvider=="postgres") {
       pg_put_line($pg, "\\.\n");
       pg_end_copy($pg);
   }
    $conn->debug=true;
    $anteriores = (int)$conn->getone("select count(*) from pac_l_rfc");
    echo "Termina base de datos ".date("c")."\n";
    echo "\nCantidad de rfc, anteriores=$anteriores nuevos=$cant\n";
    if ($cant > 0.9*$anteriores) {
        // Solo si los nuevos registros son mayores al 90% de los anteriores
        $conn->execute("drop table pac_l_rfc");
        $conn->execute("alter table temp_l_rfc rename to pac_l_rfc");
        $conn->execute("create index i_l_rfc on pac_l_rfc (rfc_rfc)");
        echo "Termina indexacion ".date("c")."\n";
    }
}

function leenodo($node,$name) {
    $paso = $node->getElementsByTagName($name);
    foreach ($paso as $otro) {
        $ret = $otro->nodeValue;
    }
    return $ret;
}

function leeurl($name,$url,$md5) {
    echo "Inicia $name : ".date("c")."\n";
    $data = file_get_contents($url);
    $resto = "con_".substr($name,17);
    file_put_contents($resto,$data);
    $hash = base64_encode(md5($data,true));
    if ($hash == $md5) {
        echo "Ok\n";
        $ret = true; 
    } else {
       echo  "Hash no coincide $hash\n";
       $ret = false; 
    }
    echo "Termina $name/$resto : ".date("c")."\n";
    return $ret;
}
?>
