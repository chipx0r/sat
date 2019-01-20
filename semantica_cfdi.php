<?php
/*****************************************************************************
 * semantica_cfdi.php Valida semantica del CFDI (actualmente solo 3.3)       *
 *                                                                           *
 * 27/dic/2016 Version inicial con numero de error inventado                 *
 *             en espera de matriz de valdiacion para bateria de pruebas     *
 *                                                                           *
 * 27/mar/2017 Primera version usando codigos de error en base a la matriz   *
 *             oficial del SAT                                               *
 *                                                                           *
 * 30/ene/2018 Se genera otra propiedad conb mas detalles del motivo del     *
 *             error en la validacion                                        *
 *****************************************************************************/
class Sem_CFDI {
    var $xml_cfd;
    var $con;
    var $codigo;
    var $status;
    var $mensaje;
    var $cuenta=true; // Para saber si ya conto la cantidad de l_rfc

    private $uso_iva8=false; // Para saber si el CFDI uso IVA del 8
    function valida($xml_cfd,$conn) {
    // {{{ valida : nodo Comprobante
        error_reporting(E_ALL);
        $ok = true;
        $this->mensaje = "";
        $this->xml_cfd = $xml_cfd;
        $this->conn = $conn;
        $Comprobante = $this->xml_cfd->getElementsByTagName('Comprobante')->item(0);
        $version = $Comprobante->getAttribute("version");
        if ($version==null) $version = $Comprobante->getAttribute("Version");
        if ($version != "3.3") {
            $this->status = "CFDI00000 Solo se valida para CFDI version 3.3";
            $this->codigo = "0 ".$this->status;
            $this->mesaje = "El CFDI es version $version";
            return true;
        }
        $Confirmacion = $Comprobante->getAttribute("Confirmacion");
        $TipoCambio = $Comprobante->getAttribute("TipoCambio");
        $TipoDeComprobante = $Comprobante->getAttribute("TipoDeComprobante");
        $Fecha = $Comprobante->getAttribute("Fecha");
        $Sello = $Comprobante->getAttribute("Sello");
        $Certificado = $Comprobante->getAttribute("Certificado");
        $FormaPago = $Comprobante->getAttribute("FormaPago");
        $MetodoPago = $Comprobante->getAttribute("MetodoPago");
        $LugarExpedicion = $Comprobante->getAttribute("LugarExpedicion");
        $CondicionesDePago = $Comprobante->getAttribute("CondicionesDePago");
        $Moneda = $Comprobante->getAttribute("Moneda");
        $SubTotal = $Comprobante->getAttribute("SubTotal");
        $Descuento = $Comprobante->getAttribute("Descuento");
        $TipoRelacion = $Comprobante->getAttribute("TipoRelacion");
        $Total = $Comprobante->getAttribute("Total");
        $regex = "(20[1-9][0-9])-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T(([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])";
        $aux = "/^$regex$/A";
        $ok = preg_match($aux,$Fecha);
        if (!$ok) {
            $this->status = "CFDI33101 El campo Fecha no cumple con el patrón requerido.";
            $this->codigo = "33101 ".$this->status;
            $this->mensaje = "El patron regex es $aux";
            return false;
        }
        $xsl = new DOMDocument("1.0","UTF-8");
        $xsl->load("xslt/cadenaoriginal_3_3.xslt");
        $proc = new XSLTProcessor();
        $proc->importStyleSheet($xsl);
        $paso = new DOMDocument("1.0","UTF-8");
        $texto = $this->xml_cfd->saveXML();
        $paso->loadXML($texto);
        $cadena = $proc->transformToXML($paso);
        $csd = "-----BEGIN CERTIFICATE-----\n".chunk_split($Certificado,64)."-----END CERTIFICATE-----\n";
        $ok = true; $pubkeyid=null;
        $x509 = @openssl_x509_read($csd);
        if ($x509===FALSE) {
            $ok = false;
            $this->mensaje="Fallo el openssl_x509_read";
        } else {
            $pubkeyid = @openssl_get_publickey($x509);
            if ($pubkeyid===FALSE) {
                $ok = false;
                $this->mensaje="Fallo el openssl_get_publickey";
            }
        }
        if (!$ok) {
            $this->status = "CFDI33105 EL certificado no cumple con alguno de los valores permitidos.";
            $this->codigo = "33105 ".$this->status;
            return false;
        }
        $ok = openssl_verify($cadena,
                             base64_decode($Sello),
                             $pubkeyid,
                             OPENSSL_ALGO_SHA256);
        // require_once("lib/debug.php");
         //if (DEBUG==511) $ok=true; // ignorar sello para 511
        if (!$ok) {
            $this->status = "CFDI33102 El resultado de la digestión debe ser igual al resultado de la desencripción del sello.";
            $this->codigo = "33102 ".$this->status;
            $this->mensaje="Fallo el openssl_verify";
            #return false;
        }
        $hay_pagos = false; $hay_cce=false;
        $Complemento = $Comprobante->getElementsByTagName("Complemento");
        if ($Complemento->length > 0) {
            $Pagos = $Complemento->item(0)->getElementsByTagName("Pagos");
            if ($Pagos->length > 0) {
                $hay_pagos = true;
            }
            $Cce = $Complemento->item(0)->getElementsByTagName("ComercioExterior");
            if ($Cce->length > 0) {
                $hay_cce = true;
            }
        }
        if ($hay_pagos && $FormaPago != null) {
            $this->status = "CFDI33103 Si existe el complemento para recepción de pagos el campo FormaPago no debe existir.";
            $this->codigo = "33103 ".$this->status;
            $this->mensaje = "hay_pagos=true FormaPago='$FormaPago'";
            return false;
        }
        if ($FormaPago != null) {
            $ok = $this->Checa_Catalogo("c_FormaPago", $FormaPago);
            if (!$ok) {
                $this->status = "CFDI33104 El campo FormaPago no contiene un valor del catálogo c_FormaPago.";
                $this->codigo = "33104 ".$this->status;
                $this->mensaje = "FormaPago='$FormaPago'";
                return false;
            }
        }
        $c_Moneda = $this->Obten_Catalogo("c_Moneda", $Moneda);
        if (sizeof($c_Moneda) == 0) {
            $this->status = "CFDI33112 El campo Moneda no contiene un valor del catálogo c_Moneda.";
            $this->codigo = "33112 ".$this->status;
                $this->mensaje = "Moneda='$Moneda'";
            return false;
        }
        $dec_moneda = (int)$c_Moneda["decimales"];
        $porc_moneda = (int)$c_Moneda["porcentaje"];
        $fac_moneda = pow(10, $dec_moneda);
        $dec = $this->cantidad_decimales($SubTotal);
        if ($dec > $dec_moneda) {
            $this->status = "CFDI33106 El valor de este campo SubTotal excede la cantidad de decimales que soporta la moneda.";
            $this->codigo = "33106 ".$this->status;
            $this->mensaje = "SubTotal=$SubTotal dec=$dec Moneda=$Moneda dec_monneda=$dec_moneda";
            return false;
        }
        $dec = $this->cantidad_decimales($Descuento);
        if ($dec > $dec_moneda) {
            $this->status = "CFDI33111 El valor de este campo Descuento excede la cantidad de decimales que soporta la moneda.";
            $this->codigo = "33111 ".$this->status;
            $this->mensaje = "Descuento=$Descuento dec=$dec Moneda=$Moneda dec_monneda=$dec_moneda";
            return false;
        }
        $Conceptos = $Comprobante->getElementsByTagName("Concepto");
        $nb_Conceptos = $Conceptos->length;
        $t_Descuento = 0; $t_Importe = 0; $t_impuestos = 0;
        $hay_Descuento = false;
        for ($i=0; $i<$nb_Conceptos; $i++) {
            $Concepto = $Conceptos->item($i);
            if ($Concepto->parentNode->nodeName!="cfdi:Conceptos") continue;
            $c_Descuento = $Concepto->getAttribute("Descuento");
            if ($c_Descuento != null) $hay_Descuento=true;
            $c_Importe = $Concepto->getAttribute("Importe");
            $t_Importe += (double)$c_Importe;
            $t_Descuento += (double)$c_Descuento;
            if ($TipoDeComprobante=="T" || $TipoDeComprobante=="P") {
                if ($c_Descuento != null) {
                    $this->status = "CFDI113; El Descuento del Concepto se debe de omitir si el tipo de comprobante es T o P.";
                    $this->codigo = "113 ".$this->status;
                    $this->mensaje = "TipoDeComprobante=$TipoDeComprobante c_Descuento=$c_Descuento";
                    return false;
                }
            }
            $Impuestos = $Concepto->getElementsByTagName("Impuestos");
            if ($Impuestos->length > 0) {
                // {{{ Hay Impuestos
                $Traslados = $Impuestos->item(0)->getElementsByTagName("Traslado");
                foreach ($Traslados as $Traslado) {
                    $t_impuestos += (double)$Traslado->getAttribute("Importe");
                }
                $Retenciones = $Impuestos->item(0)->getElementsByTagName("Retencion");
                foreach ($Retenciones as $Retencion) {
                    $t_impuestos -= (double)$Retencion->getAttribute("Importe");
                }
            } // }}} Impuestos->length > 0
        } // for cada concepto
        if ($TipoDeComprobante=="I" || $TipoDeComprobante=="E" ||
            $TipoDeComprobante=="N") {
                if (abs($SubTotal - $t_Importe)>0.001) {
                    $this->status = "CFDI33107 El TipoDeComprobante es I,E o N, el importe registrado en el campo no es igual a la suma de los importes de los conceptos registrados.";
                    $this->codigo = "33107 ".$this->status;
                    $this->mensaje = "TipoDeComprobante=$TipoDeComprobante SubTotal=$SubTotal t_Importe=$t_Importe";
                    return false;
                }
        } elseif ($TipoDeComprobante=="T" || $TipoDeComprobante=="P") {
                if ($SubTotal != 0) {
                    $this->status = "CFDI33108 El TipoDeComprobante es T o P y el importe no es igual a 0, o cero con decimales.";
                    $this->codigo = "33108 ".$this->status;
                    $this->mensaje = "TipoDeComprobante=$TipoDeComprobante SubTotal=$SubTotal";
                    return false;
                }
        }
        if ($Descuento > $SubTotal) {
            $this->status = "CFDI33109 El valor registrado en el campo Descuento no es menor o igual que el campo Subtotal.";
            $this->codigo = "33109 ".$this->status;
            $this->mensaje = "SubTotal=$SubTotal Descuento=$Descuento";
            return false;
        }
        if ($TipoDeComprobante=="I" || $TipoDeComprobante=="E" ||
            $TipoDeComprobante=="N") {
             if ($hay_Descuento) {
                $t_Descuento = round($t_Descuento,2);
                if (abs((double)$Descuento-$t_Descuento)>0.001) {
                    $this->status = "CFDI106; El Descuento no es igual a la suma de los Descuentos de los Conceptos.";
                    $this->codigo = "106 ".$this->status;
                    $this->mensaje = "t_Descuento=$t_Descuento Descuento=$Descuento";
                    return false;
                }
             } else { // No hay descuentos
                if ($Descuento != null) {
                    $this->status = "CFDI33110 El TipoDeComprobante NO es I,E o N, y un concepto incluye el campo descuento.";
                    $this->codigo = "33110 ".$this->status;
                    $this->mensaje = "TipoDeComprobante=$TipoDeComprobante Descuento=$Descuento";
                    return false;
                }
             }
        }
        $req_conf = false;
        if ($Moneda=="XXX") {
            if ($TipoCambio != null) {
                $this->status = "CFDI33115 El campo TipoCambio no se debe registrar cuando el campo Moneda tiene el valor XXX.";
                $this->codigo = "33115 ".$this->status;
                $this->mensaje = "TipoCambio=$TipoCambio Moneda=$Moneda";
                return false;
            }
        } elseif ($Moneda=="MXN") {
            if ($TipoCambio != null && $TipoCambio != "1") {
                $this->status = 'CFDI33113 El campo TipoCambio no tiene el valor "1" y la moneda indicada es MXN.';
                $this->codigo = "33113 ".$this->status;
                $this->mensaje = "TipoCambio=$TipoCambio Moneda=$Moneda";
                return false;
            }
        } else {
            if ($TipoCambio == null) {
                $this->status = "CFDI33114 El campo TipoCambio se debe registrar cuando el campo Moneda tiene un valor distinto de MXN y XXX. ";
                $this->codigo = "33114 ".$this->status;
                $this->mensaje = "TipoCambio=$TipoCambio Moneda=$Moneda";
                return false;
            }
            $regex = "[0-9]{1,14}(.([0-9]{1,6}))?";
            $aux = "/^$regex$/A";
            $ok = preg_match($aux,$TipoCambio);
            if (!$ok) {
                $this->status = "CFDI33116 El campo TipoCambio no cumple con el patrón requerido.";
                $this->codigo = "33116 ".$this->status;
                $this->mensaje = "TipoCambio=$TipoCambio Patron=$aux";
                return false;
            }
            $oficial = 20; // TODO: leer del lugar oficial (Banco de Mexico)
            $inf = $oficial * (1 - $porc_moneda/100);
            $sup = $oficial * (1 + $porc_moneda/100);
            // echo "oficial=$oficial inf=$inf sup=$sup";
            if ($TipoCambio < $inf || $TipoCambio > $sup)  {
                $req_conf = true;
                if ($Confirmacion == null) {
                    $this->status = "CFDI33117 Cuando el valor del campo TipoCambio se encuentre fuera de los límites establecidos, debe existir el campo Confirmacion.";
                    $this->codigo = "33117 ".$this->status;
                    $this->mensaje = "oficial=$oficial inf=$inf sup=$sup TipoCambio=$TipoCambio Confirmacion=$Confirmacion";
                    return false;
                }
            }
        }
        $t_impuestos = round($t_impuestos,2);
        $aux = (double)$SubTotal - (double)$Descuento + $t_impuestos;
        if (abs($Total - $aux)>0.001) {
            $this->status = "CFDI33118 El campo Total no corresponde con la suma del subtotal, menos los descuentos aplicables, más las contribuciones recibidas (impuestos trasladados - federales o locales, derechos, productos, aprovechamientos, aportaciones de seguridad social, contribuciones de mejoras) menos los impuestos retenidos.";
            $this->codigo = "33118 ".$this->status;
            $this->mensaje = "Total=$Total aux=$aux SubTotal=$SubTotal Descuento=$Descuento t_impuestos=$t_impuestos";
            return false;
        }
        // $Limite_superior=($TipoDeComprobante=="N")?2000000:100000000; // TODO : Leerlo del SAT
        // 26/jul/2017 se quita tope
        $Limite_superior=999999999999999999.999999;
        if ((double)$Total > $Limite_superior) {
            $req_conf = true;
            if ($Confirmacion == null) {
                $this->status = "CFDI33119 Cuando el valor del campo Total se encuentre fuera de los límites establecidos, debe existir el campo Confirmacion";
                $this->codigo = "33119 ".$this->status;
                $this->mensaje = "Sup=$Limite_supeior Total=$Total Confirmacion=$Confirmacion";
                return false;
            }
        }
        $ok = $this->Checa_Catalogo("c_TipoDeComprobante", $TipoDeComprobante);
        if (!$ok) {
            $this->status = "CFDI33120 El campo TipoDeComprobante, no contiene un valor del catálogo c_TipoDeComprobante.";
            $this->codigo = "33120 ".$this->status;
            $this->mensaje = "TipoDeComprobante=$TipoDeComprobante";
            return false;
        }
        if ($MetodoPago != null) {
            $ok = $this->Checa_Catalogo("c_MetodoPago", $MetodoPago);
            if (!$ok) {
                $this->status = "CFDI33121 El campo MetodoPago, no contiene un valor del catálogo c_MetodoPago.";
                $this->codigo = "33121 ".$this->status;
                $this->mensaje = "MetodoPago=$MetodoPago";
                return false;
            }
        }
        /* Ya no hay PIP
        if ($MetodoPago == "PIP" &&
            ($TipoDeComprobante=="I" || $TipoDeComprobante=="E") && 
            !$hay_pagos) {
            $this->status = "CFDI33122 Cuando se tiene el valor PIP en el campo MetodoPago y el valor en el campo TipoDeComprobante es I ó E, el CFDI debe contener un complemento de recibo de pago"; 
            $this->codigo = "33122 ".$this->status;
            $this->mensaje = "TipoDeComprobante=$TipoDeComprobante MetodoPago=$MetodoPago hay_pagos==false";
            return false;
            }
        */
        if ($TipoDeComprobante=="T" || $TipoDeComprobante=="P") {
            if ($MetodoPago != null) {
                $this->status = "CFDI33123 Se debe omitir el campo MetodoPago cuando el TipoDeComprobante es T o P";
                $this->codigo = "33123 ".$this->status;
                $this->mensaje = "TipoDeComprobante=$TipoDeComprobante MetodoPago=$MetodoPago";
                return false;
            }
        }
        if ($MetodoPago != null && $hay_pagos) {
            $this->status = "CFDI33124 Si existe el complemento para recepción de pagos en este CFDI este campo no debe existir.";
            $this->codigo = "33124 ".$this->status;
            $this->mensaje = "MetodoPago=$MetodoPago hay_pagos==true";
            return false;
        }
        $c_LugarExpedicion = $this->Obten_Catalogo("c_CP",$LugarExpedicion);
        if (sizeof($c_LugarExpedicion) == 0) {
            $this->status = "CFDI33125 El campo LugarExpedicion, no contiene un valor del catálogo c_CodigoPostal.";
            $this->codigo = "33125 ".$this->status;
            $this->mensaje = "LugarExpedicion=$LugarExpedicion";
            return false;
        }
        if ($Confirmacion != null) {
            if (!$req_conf) {
                $this->status = "CFDI33126 El campo Confirmacion no debe existir cuando los atributios TipoCambio y/o Total están dentro del rango permitido";
                $this->codigo = "33126 ".$this->status;
                $this->mensaje = "Confirmacion=$Confirmacion";
                return false;
            }
            $que = $this->Checa_Confirmacion($Confirmacion);
            // 0 : No existe en catalogo
            // 1 : existe en catalogo y esta libre
            // 2 : existe en catalogo y ya se uso
            if ($que==0) {
                $this->status = "CFDI33127 Número de confirmación inválido";
                $this->codigo = "33127 ".$this->status;
                return false;
            }
            if ($que==2) {
                $this->status = "CFDI33128 Número de confirrmación utilizado previamente.";
                $this->codigo = "33128 ".$this->status;
                return false;
            }
        }
        if ($TipoRelacion != null) {
            $ok = $this->Checa_Catalogo("c_TipoRelacion", $TipoRelacion);
            if (!$ok) {
                $this->status = "CFDI33129 El campo TipoRelacion, no contiene un valor del catálogo c_TipoRelacion.";
                $this->codigo = "33129 ".$this->status;
                $this->mensaje = "TipoRelacion=$TipoRelacion";
                return false;
            }
        }
        // }}} Comprobante
        // {{{ Emisor
        $Emisor = $Comprobante->getElementsByTagName('Emisor')->item(0);
        $rfc = $Emisor->getAttribute("Rfc");
        $RegimenFiscal = $Emisor->getAttribute("RegimenFiscal");
        $ok = $this->Checa_Catalogo("c_RegimenFiscal", $RegimenFiscal);
        if (!$ok) {
            $this->status = "CFDI33130 El campo RegimenFiscal, no contiene un valor del catálogo c_RegimenFiscal.";
            $this->codigo = "33130 ".$this->status;
            $this->mensaje = "RegimenFiscal=$RegimenFiscal";
            return false;
        }
        $regimen = (strlen($rfc)==13) ? "c_RegimenFisica" : "c_RegimenMoral";
        $ok = $this->Checa_Catalogo($regimen, $RegimenFiscal);
        if (!$ok) {
            $this->status = "CFDI33131 La clave del campo RegimenFiscal debe corresponder con el tipo de persona (fisica o moral).";
            $this->codigo = "33131 ".$this->status;
            $this->mensaje = "RegimenFiscal=$RegimenFiscal";
            return false;
        }
        // }}} Emisor
        // {{{ Receptor
        $Receptor = $Comprobante->getElementsByTagName('Receptor')->item(0);
        $rfcReceptor = $Receptor->getAttribute("Rfc");
        $ResidenciaFiscal = $Receptor->getAttribute("ResidenciaFiscal");
        $NumRegIdTrib = $Receptor->getAttribute("NumRegIdTrib");
        $row= $this->lee_l_rfc($rfcReceptor);
        if ($ResidenciaFiscal != null) {
            $c_Pais = $this->Obten_Catalogo("c_Pais", $ResidenciaFiscal);
            if (!$c_Pais) {
                $this->status = "CFDI33133 El campo ResidenciaFiscal, no contiene un valor del catálogo c_Pais.";
                $this->codigo = "33133 ".$this->status;
                $this->mensaje = "ResidenciaFiscal=$ResidenciaFiscal";
                return false;
            }
            $lista_taxid = trim($c_Pais["lista_taxid"]);
            $regex_taxid = trim($c_Pais["regex_taxid"]);
        } else {
            $lista_taxid = null;
            $regex_taxid = null;
        }
        if ($rfcReceptor!="XAXX010101000"&&$rfcReceptor!="XEXX010101000") 
            if (sizeof($row)==0) {
                $this->status = "CFDI33132 Este RFC del receptor no existe en la lista de RFC inscritos no cancelados del SAT.";
                $this->codigo = "33132 ".$this->status;
                $this->mensaje = "rfcReceptor=$rfcReceptor";
                return false;
            }
        if ($rfcReceptor=="XAXX010101000" || sizeof($row)!=0) {
            // Si es generico nacional o RFC de la lista oficial
            if ($ResidenciaFiscal!=null) {
                $this->status = "CFDI33134 El RFC del receptor es de un RFC registrado en el SAT o un RFC genérico nacional y EXISTE el campo ResidenciaFiscal.";
                $this->codigo = "33134 ".$this->status;
                $this->mensaje = "rfcReceptor=$rfcReceptor ResidenciaFiscal=$ResidenciaFiscal";
                return false;
            }
        }
        if ($ResidenciaFiscal=="MEX") {
            $this->status = "CFDI33135 El valor del campo ResidenciaFiscal no puede ser MEX.";
            $this->codigo = "33135 ".$this->status;
            $this->mensaje = "ResidenciaFiscal=$ResidenciaFiscal";
            return false;
        }
        if ($rfcReceptor=="XEXX010101000"&&($hay_cce||$NumRegIdTrib!=null)) {
            if ($ResidenciaFiscal==null) {
                $this->status = "CFDI33136 Se debe registrar un valor de acuerdo al catálogo c_Pais en en el campo ResidenciaFiscal, cuando en el en el campo NumRegIdTrib se registre información.";
                $this->codigo = "33136 ".$this->status;
                $this->mensaje = "NumRegIdTrib=$NumRegIdTrib ResidenciaFiscal=$ResidenciaFiscal";
                return false;
            }
        }
        if ($rfcReceptor=="XAXX010101000" || sizeof($row)!=0) {
            if ($NumRegIdTrib!=null) {
                $this->status = "CFDI33137 El valor del campo es un RFC inscrito no cancelado en el SAT o un RFC genérico nacional, y se registró el campo NumRegIdTrib.";
                $this->codigo = "33137 ".$this->status;
                $this->mensaje = "rfcReceptor=$rfcReceptor ResidenciaFiscal=$ResidenciaFiscal";
                return false;
            }
        }
        if ($rfcReceptor=="XEXX010101000"&&$hay_cce) {
            if ($NumRegIdTrib==null) {
                $this->status = "CFDI33138 Para registrar el campo NumRegIdTrib, el CFDI debe contener el complemento de comercio exterior y el RFC del receptor debe ser un RFC genérico extranjero.";
                $this->codigo = "33138 ".$this->status;
                $this->mensaje = "rfcReceptor=$rfcReceptor hay_cce==true";
                return false;
            }
        }
        if ($NumRegIdTrib!=null && $regex_taxid!="") { 
            $aux = "/^$regex_taxid$/A";
            $ok = preg_match($aux,$NumRegIdTrib);
            if (!$ok) {
                $this->status = "CFDI33139 El campo NumRegIdTrib no cumple con el patrón correspondiente.";
                $this->codigo = "33139 ".$this->status;
                $this->mensaje = "NumRegIdTrib=$NumRegIdTrib patron=$aux";
                return false;
            }
        }
        $UsoCFDI = $Receptor->getAttribute("UsoCFDI");
        $ok = $this->Checa_Catalogo("c_usoCFDI", $UsoCFDI);
        if (!$ok) {
            $this->status = "CFDI33140 El campo UsoCFDI, no contiene un valor del catálogo c_UsoCFDI.";
            $this->codigo = "33140 ".$this->status;
            $this->mensaje = "UsoCFDI=$UsoCFDI";
            return false;
        }
        $uso = (strlen($rfcReceptor)==13) ? "c_usoCFDIFisica" : "c_usoCFDIMoral";
        $ok = $this->Checa_Catalogo($uso, $UsoCFDI);
        if (!$ok) {
            $this->status = "CFDI33141 La clave del campo UsoCFDI debe corresponder con el tipo de persona (fisica o moral).";
            $this->codigo = "33141 ".$this->status;
            $this->mensaje = "UsoCFDI=$UsoCFDI";
            return false;
        }
        // }}} Receptor
        // {{{ Conceptos
        $acum_rete = array(); $acum_tras=array();
        $gt_rete=0; $gt_tras=0;
        for ($i=0; $i<$nb_Conceptos; $i++) {
            $Concepto = $Conceptos->item($i);
            if ($Concepto->parentNode->nodeName!="cfdi:Conceptos") continue;
            $ClaveProdServ = $Concepto->getAttribute("ClaveProdServ");
            $c_ProdServ = $this->Obten_Catalogo("c_ClaveProdServ", $ClaveProdServ);
            if (sizeof($c_ProdServ) == 0) {
                $this->status = "CFDI33142 El campo ClaveProdServ, no contiene un valor del catálogo c_ClaveProdServ.";
                $this->codigo = "33142 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ";
                return false;
            }
            $compls = $c_ProdServ["complementos"];
            if ($compls != "") {
                $ok=false;
                $lista = $Complemento->item(0)->childNodes;
                foreach ($lista as $nodo) {
                    $nombre = explode(":",$nodo->nodeName);
                    if ($nombre[0]==$compls) $ok = true;
                }
                if (!$ok) {
                    $this->status = "CFDI33143 No existe el complemento requerido para el valor de ClaveProdServ.";
                    $this->codigo = "33143 ".$this->status;
                    $this->mensaje = "ClaveProdServ=$ClaveProdServ compls=$compls";
                    return false;
                }
            }
            $impus = $c_ProdServ["impuestos"];
            if ($impus != "") {
                $ok=false;
                $Impuestos = $Concepto->getElementsByTagName('Impuestos');
                if ($Impuestos->length>0) {
                    $Traslados = $Impuestos->item(0)->getElementsByTagName('Traslado');
                    for ($j=0; $j<$Traslados->length; $j++) {
                        $Traslado=$Traslados->item($j);
                        $Impuesto = $Traslado->getAttribute("Impuesto");
                        if ($Impuesto==$impus) $ok = true;
                    }
                }
                if (!$ok) {
                    $this->status = "CFDI33144 No esta declarado el impuesto relacionado con el valor de ClaveProdServ.";
                    $this->codigo = "33144 ".$this->status;
                    $this->mensaje = "ClaveProdServ=$ClaveProdServ impus=$impus";
                    return false;
                }
            }
            $ClaveUnidad = $Concepto->getAttribute("ClaveUnidad");
            $ok = $this->Checa_Catalogo("c_ClaveUnidad", $ClaveUnidad);
            if (!$ok) {
                $this->status = "CFDI33145 El campo ClaveUnidad no contiene un valor del catálogo c_ClaveUnidad.";
                $this->codigo = "33145 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ ClaveUnidad=$ClaveUnidad";
                return false;
            }
            $ValorUnitario = $Concepto->getAttribute("ValorUnitario");
            $dec_valor = $this->cantidad_decimales($ValorUnitario);
            if ( ($TipoDeComprobante=="I" || $TipoDeComprobante=="E" || $TipoDeComprobante=="N") && (double)$ValorUnitario <= 0) {
                $this->status = "CFDI33147 El valor valor del campo ValorUnitario debe ser mayor que cero (0) cuando el tipo de comprobante es Ingreso, Egreso o Nomina.";
                $this->codigo = "33147 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ TipoDeComprobante=$TipoDeComprobante ValorUnitario=$ValorUnitario";
                return false;
            }
            $Cantidad = $Concepto->getAttribute("Cantidad");
            $dec_cant = $this->cantidad_decimales($Cantidad);
            $Importe = $Concepto->getAttribute("Importe");
            $dec_impo = $this->cantidad_decimales($Importe);
            $inf = ($Cantidad - pow(10,-1*$dec_cant)/2)*($ValorUnitario - pow(10,-1*$dec_valor)/2);
            $inf = floor($inf * $fac_moneda) / $fac_moneda;
            $sup = ($Cantidad + pow(10,-1*$dec_cant)/2-pow(10,-12))*($ValorUnitario + pow(10,-1 * $dec_valor)/2-pow(10,-12));
            $sup = ceil($sup * $fac_moneda) / $fac_moneda;
            $impo = (double)$Importe;
            if ($impo < $inf || $impo > $sup) {
                $this->status = "CFDI33149 El valor del campo Importe no se encuentra entre el limite inferior y superior permitido.";
                $this->codigo = "33149 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ impo=$impo inf=$inf sup=$sup fac_moneda=$fac_moneda";
                return false;
            }
            $Descuento = $Concepto->getAttribute("Descuento");
            $dec_desc = $this->cantidad_decimales($Descuento);
            if ($dec_desc > $dec_impo) {
                $this->status = "CFDI33150 El valor del campo Descuento debe tener hasta la cantidad de decimales que tenga registrado el atributo importe del concepto.";
                $this->codigo = "33150 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ Descuento=$Descuento dec_desc=$dec_desc dec_impo=$dec_impo impo=$impo";
                return false;
            }
            if ((double)$Descuento > $impo) {
                $this->status = "CFDI33151 El valor del campo Descuento es mayor que el campo Importe.";
                $this->codigo = "33151 ".$this->status;
                $this->mensaje = "ClaveProdServ=$ClaveProdServ Descuento=$Descuento impo=$impo";
                return false;
            }
            $Impuestos = $Concepto->getElementsByTagName('Impuestos');
            for ($indi=0; $indi<$Impuestos->length; $indi++) {
                $nodo = $Impuestos->item($indi);
                if (!$nodo->parentNode->isSameNode($Concepto)) continue;
                $Traslados = $nodo->getElementsByTagName('Traslado');
                $Retenciones = $nodo->getElementsByTagName('Retencion');
                if ($Traslados->length==0 && $Retenciones->length==0) {
                    $this->status = "CFDI33152 En caso de utilizar el nodo Impuestos en un concepto, se deben incluir impuestos  de traslado y/o retenciones.";
                    $this->codigo = "33152 ".$this->status;
                    return false;
                }
                for ($j=0; $j<$Traslados->length; $j++) {
                    $Traslado=$Traslados->item($j);
                    $Base = $Traslado->getAttribute("Base");
                    $dec_base = $this->cantidad_decimales($Base);
                    if ((double)$Base<=0) {
                        $this->status = "CFDI33154 El valor del campo Base que corresponde a Traslado debe ser mayor que cero.";
                        $this->codigo = "33154 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Base=$Base";
                        return false;
                    }
                    $Impuesto = $Traslado->getAttribute("Impuesto");
                    $ok = $this->Checa_Catalogo("c_Impuesto", $Impuesto);
                    if (!$ok) {
                        $this->status = "CFDI33155 El valor del campo Impuesto que corresponde a Traslado no contiene un valor del catálogo c_Impuesto.";
                        $this->codigo = "33155 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto";
                        return false;
                    }
                    $TipoFactor = $Traslado->getAttribute("TipoFactor");
                    $ok = $this->Checa_Catalogo("c_TipoFactor", $TipoFactor);
                    if (!$ok) {
                        $this->status = "CFDI33156 El valor del campo TipoFactor que corresponde a Traslado no contiene un valor del catálogo c_TipoFactor.";
                        $this->codigo = "33156 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto TipoFactor=$TipoFactor";
                        return false;
                    }
                    $TasaOCuota = $Traslado->getAttribute("TasaOCuota");
                    $i_Importe = $Traslado->getAttribute("Importe");
                    if ($TipoFactor=="Exento") {
                        if ($TasaOCuota != null || $i_Importe != null) {
                            $this->status = "CFDI33157 Si el valor registrado en el campo TipoFactor que corresponde a Traslado es Exento no se deben registrar los campos TasaOCuota ni Importe.";
                            $this->codigo = "33157 ".$this->status;
                            $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto TipoFactor=$TipoFactor TasaOCuota=$TasaOCuota i_importe=$i_Importe";
                            return false;
                        }
                    }
                    if ($TipoFactor=="Tasa" || $TipoFactor=="Cuota") {
                        if ($TasaOCuota == null || $i_Importe == null) {
                            $this->status = "CFDI33158 Si el valor registrado en el campo TipoFactor que corresponde a Traslado es Tasa o Cuota, se deben registrar los campos TasaOCuota e Importe.";
                            $this->codigo = "33158 ".$this->status;
                            $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto TipoFactor=$TipoFactor TasaOCuota=$TasaOCuota i_importe=$i_Importe";
                            return false;
                        }
                        if ($Impuesto=="003" && $TipoFactor=="Cuota" && $TasaOCuota < 43.77) { // IEPS
                            $ok=true;
                        } else {
                            $row = $this->Obten_Catalogo("c_TasaOCuotaTras",$TasaOCuota,$Impuesto,$TipoFactor);
                            if (sizeof($row) == 0) {
                                $this->status = "CFDI33159 El valor del campo TasaOCuota que corresponde a Traslado no contiene un valor del catálogo c_TasaOcuota o se encuentra fuera de rango.";
                                $this->codigo = "33159 ".$this->status;
                                $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto TipoFactor=$TipoFactor TasaOCuota=$TasaOCuota i_importe=$i_Importe";
                                return false;
                            } // No existe en cata
                            if ($Impuesto=="002" && $TipoFactor="Tasa" && $TasaOCuota == 0.0800) { // IVA del 8 
                                $this->uso_iva8 = true;
                                $prod_dec = (int)$c_ProdServ['decimales'];
                                if ($rfcReceptor=="XAXX010101000" && $ClaveProdServ=="01010101") {
                                    // Si es publico en gemeral prod generico
                                     $prod_dec = 1;
                                }
                                if ($prod_dec!=1) {
                                    $this->status = "CFDI33196 No aplica Estiulo Franja Fronteriza para la clave de producto o servicio";
                                    $this->codigo = "33196 ".$this->status;
                                    $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado Impuesto=$Impuesto TipoFactor=$TipoFactor TasaOCuota=$TasaOCuota i_importe=$i_Importe estimulo=$prod_dec";
                                    return false;
                                }
                            }
                        } // NO es rango de IEPS
                    } // Si es tasa o cuota
                    if ($i_Importe != null) {
                        $dec_importe = $this->cantidad_decimales($i_Importe);
                        $inf = ($Base - pow(10,-1*$dec_base)/2)*$TasaOCuota;;
                        $inf = floor($inf * $fac_moneda) / $fac_moneda;
                        $sup = ($Base + pow(10,-1*$dec_base)/2-pow(10,-12))*$TasaOCuota;
                        $sup = ceil($sup * $fac_moneda) / $fac_moneda;
                        $impo = (double)$i_Importe;
                        if ($impo < $inf || $impo > $sup) {
                            $this->status = "CFDI33161 El valor del campo Importe o que corresponde a Traslado no se encuentra entre el limite inferior y superior permitido.";
                            $this->codigo = "33161 ".$this->status;
                            $this->mensaje = "ClaveProdServ=$ClaveProdServ Traslado impo=$impo inf=$inf sup=$sup dec_base=$dec_base TasaOCuota=$TasaOCuota";
                            return false;
                        }
                    }
                    $llave=$Impuesto.$TasaOCuota;
                    if (!array_key_exists($llave,$acum_tras)) $acum_tras[$llave] = 0;
                    $acum_tras[$llave] += $i_Importe;
                    $gt_tras += $i_Importe;
                }
                for ($j=0; $j<$Retenciones->length; $j++) {
                    $Retencion=$Retenciones->item($j);
                    $Base = $Retencion->getAttribute("Base");
                    $dec_base = $this->cantidad_decimales($Base);
                    if ((double)$Base<=0) {
                        $this->status = "CFDI33163 El valor del campo Base que corresponde a Retención debe ser mayor que cero.";
                        $this->codigo = "33163 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base";
                        return false;
                    }
                    $Impuesto = $Retencion->getAttribute("Impuesto");
                    $ok = $this->Checa_Catalogo("c_Impuesto", $Impuesto);
                    if (!$ok) {
                        $this->status = "CFDI33164 El valor del campo Impuesto que corresponde a Retencion no contiene un valor del catálogo c_Impuesto.";
                        $this->codigo = "33164 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base Impuesto=$Impuesto";
                        return false;
                    }
                    $TipoFactor = $Retencion->getAttribute("TipoFactor");
                    $ok = $this->Checa_Catalogo("c_TipoFactor", $TipoFactor);
                    if (!$ok) {
                        $this->status = "CFDI33165 El valor del campo TipoFactor que corresponde a Retencion no contiene un valor del catálogo c_TipoFactor.";
                        $this->codigo = "33165 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base Impuesto=$Impuesto TipoFactor=$TipoFactor";
                        return false;
                    }
                    if ($TipoFactor=="Exento") {
                        $this->status = "CFDI33166 Si el valor registrado en el campo TipoFactor que corresponde a Retención debe ser distinto de Exento.";
                        $this->codigo = "33166 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base Impuesto=$Impuesto TipoFactor=$TipoFactor";
                        return false;
                    }
                    $TasaOCuota = $Retencion->getAttribute("TasaOCuota");
                    if ( ($Impuesto=="001"&&$TipoFactor=="Tasa"&&$TasaOCuota<0.35) || // ISR correcto si <= 35%
                         ($Impuesto=="002"&&$TipoFactor=="Tasa"&&$TasaOCuota<0.16) || // IVA correcto si <= 16%
                         ($Impuesto=="003"&&$TipoFactor=="Cuota"&&$TasaOCuota<43.77) ) { // IEPS correcto si <= 43.77
                         $ok = true;
                    } else { // Si no es rango, busca catalogo
                        $row = $this->Obten_Catalogo("c_TasaOCuotaRet",$TasaOCuota,$Impuesto,$TipoFactor);
                        if (sizeof($row) == 0) {
                            $this->status = "CFDI33167 El valor del campo TasaOCuota que corresponde a Retención no contiene un valor del catálogo c_TasaOcuota o se encuentra fuera de rango.";
                            $this->codigo = "33167 ".$this->status;
                            $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base Impuesto=$Impuesto TipoFactor=$TipoFactor TasaOCuota=$TasaOCuota";
                            return false;
                        }
                    } // No es rango de impuesto
                    $i_Importe = $Retencion->getAttribute("Importe");
                    $dec_impo = $this->cantidad_decimales($i_Importe);
                    $inf = ($Base - pow(10,-1*$dec_base)/2)*$TasaOCuota;
                    $inf = floor($inf * $fac_moneda) / $fac_moneda;
                    $sup = ($Base + pow(10,-1*$dec_base)/2-pow(10,-12))*$TasaOCuota;
                    $sup = ceil($sup * $fac_moneda) / $fac_moneda;
                    $impo = (double)$i_Importe;
                    if ($impo < $inf || $impo > $sup) {
                        $this->status = "CFDI33169 El valor del campo Importe que corresponde a Retención no se encuentra entre el limite inferior y superior permitido.";
                        $this->codigo = "33169 ".$this->status." Inf=$inf sup=$sup impo=$impo";;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ Retencion Base=$Base Impuesto=$Impuesto inf=$inf sup=$sup TasaOCuota=$TasaOCuota";
                        return false;
                    }
                    if (!array_key_exists($Impuesto,$acum_rete)) $acum_rete[$Impuesto] = 0;
                    $acum_rete[$Impuesto] += $impo;
                    $gt_rete += $impo;
                }
            }
            $info = $Concepto->getElementsByTagName('InformacionAduanera');
            if ($info->length>0) {
                for ($j=0; $j<$info->length; $j++) {
                    $InformacionAduanera=$info->item($j);
                    // Para que solo tome InformacionAduanera a nivel 
                    // concepto y no a nivel Parte
                    if (!$InformacionAduanera->parentNode->isSameNode($Concepto)) continue;
                    $NumeroPedimento = $InformacionAduanera->getAttribute("NumeroPedimento");
                    if ($hay_cce && $NumeroPedimento != null) {
                        $this->status = "CFDI33171 El NumeroPedimento no debe existir si se incluye el complemento de comercio exterior.";
                        $this->codigo = "33171 ".$this->status;
                        $this->mensaje = "ClaveProdServ=$ClaveProdServ NumeroPedimento=$NumeroPedimento";
                        return false;
                    } elseif ($NumeroPedimento != null) {
                        $err= $this->valida_pedimento($NumeroPedimento);
                        if ($err > 0) {
                            $this->status = "CFDI33170 El número de pedimento es inválido.";
                            $this->codigo = "33170 ".$this->status;
                            return false;
                        }
                    } // Validacion de Pedimento de concepto
                } // Para cada informacion
            } // Hay Informacion aduanera en el Concepto
            $sv=$ClaveProdServ;
            $Partes = $Concepto->getElementsByTagName('Parte');
            if ($Partes->length>0) {
                for ($j=0; $j<$Partes->length; $j++) {
                    $Parte=$Partes->item($j);
                    $ClaveProdServ = $Parte->getAttribute('ClaveProdServ');
                    $ok = $this->Checa_Catalogo("c_ClaveProdServ", $ClaveProdServ);
                    if (!$ok) {
                        $this->status = "CFDI33172 El campo ClaveProdServ, no contiene un valor del catálogo c_ClaveProdServ.";
                        $this->codigo = "33172 ".$this->status;
                        $this->mensaje = "Concepto ClaveProdServ=$sv parte ClaveProdServ=$ClaveProdServ no en c_ClaveProdServ";
                        return false;
                    }
                    $ValorUnitario = $Parte->getAttribute('ValorUnitario');
                    $Importe = $Parte->getAttribute('Importe');
                    if ($ValorUnitario!= "" || $Importe != "") {
                        $dec_valor = $this->cantidad_decimales($ValorUnitario);
                        if ((double)$ValorUnitario <= 0) {
                            $this->status = "CFDI33174 El valor del campo ValorUnitario debe ser mayor que cero (0).";
                            $this->mensaje = "Concepto ClaveProdServ=$sv parte ClaveProdServ=$ClaveProdServ ValorUnitario=$ValorUnitario Importe=$Importe";
                            $this->codigo = "33174 ".$this->status;
                            return false;
                        }
                        $dec_impo = $this->cantidad_decimales($Importe);
                        $Cantidad = $Parte->getAttribute('Cantidad');
                        $dec_cant = $this->cantidad_decimales($Cantidad);
                        $inf = ($Cantidad - pow(10,-1*$dec_cant)/2)*($ValorUnitario - pow(10,-1*$dec_valor)/2);
                        $inf = floor($inf * $fac_moneda) / $fac_moneda;
                        $sup = ($Cantidad + pow(10,-1*$dec_cant)/2-pow(10,-12))*($ValorUnitario + pow(10,-1 * $dec_valor)/2-pow(10,-12));
                        $sup = ceil($sup * $fac_moneda) / $fac_moneda;
                        $impo = (double)$Importe;
                        if ($impo < $inf || $impo > $sup) {
                            $this->status = "CFDI33176 El valor del campo Importe no se encuentra entre el limite inferior y superior permitido.";
                            $this->codigo = "33176 ".$this->status;
                            $this->mensaje = "Concepto ClaveProdServ=$sv parte ClaveProdServ=$ClaveProdServ ValorUnitario=$ValorUnitario Importe=$Importe inf=$inf sup=$sup";
                            return false;
                        }
                    }
                    $info = $Parte->getElementsByTagName('InformacionAduanera');
                    if ($info->length>0) {
                        for ($j=0; $j<$info->length; $j++) {
                            $InformacionAduanera=$info->item($j);
                            $NumeroPedimento = $InformacionAduanera->getAttribute("NumeroPedimento");
                            if ($hay_cce && $NumeroPedimento != null) {
                                $this->status = "CFDI33178 El NumeroPedimento no debe existir si se incluye el complemento de comercio exterior.";
                                $this->mensaje = "Concepto ClaveProdServ=$sv parte ClaveProdServ=$ClaveProdServ NumeroPedimento=$NumeroPedimento";
                                $this->codigo = "33178 ".$this->status;
                                return false;
                            } elseif ($NumeroPedimento != null) {
                                $err= $this->valida_pedimento($NumeroPedimento);
                                if ($err > 0) {
                                    $this->status = "CFDI33177 El número de pedimento es inválido.";
                                    $this->codigo = "33177 ".$this->status;
                                    return false;
                                }
                            } // Valida pedimento de parte
                        } // para cada informacion aduanera
                    } // SI hay Informacion aduanera
                } // Para cada Parte
            } // Hay Partes
        } // Para cada concepto
        // }}} Conceptos
        // {{{ Impuestos
        $Impuestos = $Comprobante->getElementsByTagName('Impuestos');
        $gt_impu=0;
        foreach ($Impuestos as $Impuesto) {
            // Para que solo tome Impuestos a nivel comprobante y no a
            // nivel Concepto
            if (!$Impuesto->parentNode->isSameNode($Comprobante)) continue;
            //
            if ($TipoDeComprobante=="T" || $TipoDeComprobante=="P") {
                $this->status = "CFDI33179 Cuando el TipoDeComprobante sea T o P, el elemento Impuestos no debe existir.";
                $this->codigo = "33179 ".$this->status;
                $this->mensaje = "TipoDeComprobante=$TipoDeComprobante";
                return false;
            }
            $TotalImpuestosRetenidos=$Impuesto->getAttribute("TotalImpuestosRetenidos");
            $dec_impo = $this->cantidad_decimales($TotalImpuestosRetenidos);
            if ($dec_impo > $dec_moneda) {
                $this->status = "CFDI33180 El valor del campo TotalImpuestosRetenidos debe tener hasta la cantidad de decimales que soporte la moneda.";
                $this->codigo = "33180 ".$this->status;
                $this->mensaje = "TotalImpuestosRetenidos=$TotalImpuestosRetenidos dec_impo=$dec_impo dec_moneda=$dec_moneda";
                return false;
            }
            $TotalImpuestosTrasladados=$Impuesto->getAttribute("TotalImpuestosTrasladados");
            $dec_impo = $this->cantidad_decimales($TotalImpuestosTrasladados);
            if ($dec_impo > $dec_moneda) {
                $this->status = "CFDI33182 El valor del campo TotalImpuestosTrasladados debe tener hasta la cantidad de decimales que soporte la moneda.";
                $this->codigo = "33182 ".$this->status;
                $this->mensaje = "TotalImpuestosTrasladados=$TotalImpuestosTrasladados dec_impo=$dec_impo dec_moneda=$dec_moneda";
                return false;
            }
            $t_rete=0; $t_tras=0;
            $rete = array();
            $Retenciones = $Impuesto->getElementsByTagName('Retencion');
            foreach ($Retenciones as $Retencion) {
                $gt_impu++;
                $impu=$Retencion->getAttribute("Impuesto");
                $ok = $this->Checa_Catalogo("c_Impuesto", $impu);
                if (!$ok) {
                    $this->status = "CFDI33185 El campo Impuesto no contiene un valor del catálogo c_Impuesto.";
                    $this->codigo = "33185 ".$this->status;
                    $this->mensaje = "totales retencion Impuesto=$impu";
                    return false;
                }
                $impo=$Retencion->getAttribute("Importe");
                if (array_key_exists($impu,$rete)) {
                    $this->status = "CFDI33186 Debe haber sólo un registro por cada tipo de impuesto retenido.";
                    $this->mensaje = "totales retencion Impuesto=$impu";
                    $this->codigo = "33186 ".$this->status;
                    return false;
                }
                $rete[$impu]=$impo;
                $t_rete += (double)$impo;
                if ($TotalImpuestosRetenidos==null) {
                    $this->status = "CFDI33184 Debe existir el campo TotalImpuestosRetenidos.";
                    $this->codigo = "33184 ".$this->status;
                    $this->mensaje = "totales retencion TotalImpuestosRetenidos=$TotalImpuestosRetenidos";
                    return false;
                }
                $dec_impo = $this->cantidad_decimales($impo);
                if (!array_key_exists($impu,$acum_rete) ||
                    abs($acum_rete[$impu]-$impo)>0.001) {
                    $this->status = "CFDI33189 El campo Importe correspondiente a Retención no es igual a la suma de los importes de los impuestos retenidos registrados en los conceptos donde el impuesto sea igual al campo impuesto de este elemento.";
                    $this->codigo = "33189 ".$this->status;
                    $this->mensaje = "totales retencion impu=$impu impo=$impo";;
                    return false;
                }
            }
            if (abs($t_rete-(double)$TotalImpuestosRetenidos)>0.001) {
                $this->status = "CFDI33181 El valor del campo TotalImpuestosRetenidos debe ser igual a la suma de los importes registrados en el elemento hijo Retencion.";
                $this->codigo = "33181 ".$this->status;
                $this->mensaje = "totales retencion t_rete=$t_rete TotalImpuestosRetenidos=$TotalImpuestosRetenidos";
                return false;
            }
            $tras = array();
            $Traslados = $Impuesto->getElementsByTagName('Traslado');
            foreach ($Traslados as $Traslado) {
                $gt_impu++;
                if ($TotalImpuestosTrasladados==null) {
                    $this->status = "CFDI33190 Debe existir el campo TotalImpuestosTrasladados.";
                    $this->codigo = "33190 ".$this->status;
                    $this->mensaje = "totales traslado TotalImpuestosTrasladados=$TotalImpuestosTrasladados";
                    return false;
                }
                $impu=$Traslado->getAttribute("Impuesto");
                $ok = $this->Checa_Catalogo("c_Impuesto", $impu);
                if (!$ok) {
                    $this->status = "CFDI33191 El campo Impuesto no contiene un valor del catálogo c_Impuesto.";
                    $this->codigo = "33191 ".$this->status;
                    $this->mensaje = "totales traslado Impuesto=$impu";
                    return false;
                }
                $TasaOCuota=$Traslado->getAttribute("TasaOCuota");
                $TipoFactor=$Traslado->getAttribute("TipoFactor");
                $llave=$impu.$TasaOCuota;
                if (array_key_exists($llave,$tras)) {
                    $this->status = "CFDI33192 Debe haber sólo un registro con la misma combinación de impuesto, factor y tasa por cada traslado.";
                    $this->codigo = "33192 ".$this->status;
                    $this->mensaje = "totales traslado Impuesto=$impu llave=$llave";
                    return false;
                }
                $tras[$llave] = true;
                if ($impu=="003" && $TipoFactor=="Cuota" && $TasaOCuota < 43.77) { // IEPS
                    $ok=true;
                } else {
                    $row = $this->Obten_Catalogo("c_TasaOCuotaTras",$TasaOCuota,$impu,$TipoFactor);
                    if (sizeof($row) == 0) {
                        $this->status = "CFDI33193 El valor seleccionado debe corresponder a un valor del catalogo donde la columna impuesto corresponda con el campo impuesto y la columna factor corresponda con el campo TipoFactor.";
                        $this->codigo = "33193 ".$this->status;
                        $this->mensaje = "totales traslado Impuesto=$impu TasaOCuota=$TasaOCuota TipoFactor=$TipoFactor";
                        return false;
                    } // NO hay registro
                } // es IEPS
                $impo=$Traslado->getAttribute("Importe");
                $t_tras += (double)$impo;
                $dec_impo = $this->cantidad_decimales($impo);
                if (!array_key_exists($llave,$acum_tras) ||
                    abs(round($acum_tras[$llave],2)-$impo)>0.001) {
                    $this->status = "CFDI33195 El campo Importe correspondiente a Traslado no es igual a la suma de los importes de los impuestos trasladados registrados en los conceptos donde el impuesto del concepto sea igual al campo impuesto de este elemento y la TasaOCuota del concepto sea igual al campo TasaOCuota de este elemento.";
                    $this->codigo = "33195 ".$this->status;
                    $this->mensaje = "totales traslado acum_tras=".$acum_tras[$llave]." impo=$impo llave=$llave";
                    return false;
                }
            }
            if (abs($t_tras-$TotalImpuestosTrasladados)>0.001) {
                $this->status = "CFDI33183 El valor del campo TotalImpuestosTrasladados no es igual a la suma de los importes registrados en el elemento hijo Traslado.";
                $this->codigo = "33183 ".$this->status;
                $this->mensaje = "totales traslado t_tras=$t_tras TotalImpuestosTrasladados=$TotalImpuestosTrasladados";
                return false;
            }
            //
        }
        // Si hubo Retenciones/trraslados en conceptos y no en totales ....
        if ( ($gt_rete > 0 || $gt_tras > 0) && $gt_impu == 0) {
            $this->status = "CFDI33187 Debe existir el campo TotalImpuestosRetenidos.";
            $this->codigo = "33187 ".$this->status;
            $this->mensaje = "gt_rete=$gt_rete gt_tras=$gt_tras gt_impu=$gt_impu";
            return false;
        }
        // }}} Impuestos
        if ($this->uso_iva8) {
            $nocert = $Comprobante->getAttribute("NoCertificado");
            $row = $this->conn->getrow("select lco_valido from pac_lco 
                       where lco_rfc = '$rfc' and
                             lco_valido in ('1','2') and
                             lco_status = 'A' and
                             lco_certificado = '$nocert'");
            $lco_valido = (sizeof($row)>0) ? $row['lco_valido']:'0';
            if ($lco_valido != "2") {
                $this->status = "CFDI33196 El RFC no se encuentra registrado para aplicar el Estimulo Franja Fronteriza";
                $this->codigo = "33196 ".$this->status;
                $this->mensaje = "nocert=$nocert rfc=$rfc lco_valido=".$lco_valido;
                return false;
            }
            $dec_cp = (int)$c_LugarExpedicion["decimales"];
            if ($dec_cp!=1) {
                $this->status = "CFDI33196 El codigo postal no corresponde a franja fronteriza.";
                $this->codigo = "33196 ".$this->status;
                $this->mensaje = "LugarExpedicion=$LugarExpedicion dec_cp=$dec_cp";
                return false;
            }
        }
        $this->status = "CFDI00000 Validacion semantica de CFDI 3.3 Correcta";
        $this->codigo = "0 ".$this->status;
        return $ok;
    }
    // {{{ Checa_Catalogo
    private function Checa_Catalogo($catalogo,$llave,$prm1="",$prm2="",$prm3="") {
        $ok = true;
        $rs = $this->Obten_catalogo($catalogo,$llave,$prm1,$prm2,$prm3);
        if ($rs===FALSE) return false;
        if (sizeof($rs)==0) return false;
        return $ok;
    }
    // }}}
    // {{{ Oeten_Catalogo
    private function Obten_Catalogo($catalogo,$llave,$prm1="",$prm2="",$prm3="") {
        $rs = false;
        $cata = $this->conn->qstr($catalogo);
        $l = $this->conn->qstr($llave);
        $qry = "select * from pac_Catalogos where cata_cata = $cata and cata_llave = $l";
        if ($prm1!="") {
            $p = $this->conn->qstr($prm1);
            $qry .= " and cata_prm1 = $p";
        }
        if ($prm2!="") {
            $p = $this->conn->qstr($prm2);
            $qry .= " and cata_prm2 = $p";
        }
        if ($prm3!="") {
            $p = $this->conn->qstr($prm3);
            $qry .= " and cataprm3 = $p";
        }
        $rs = $this->conn->getrow($qry);
        return $rs;
    }
    // }}}
    // {{{ Cuenta_Catalogo
    private function Cuenta_Catalogo($catalogo,$prm1) {
        $cant = 0;
        $cata = $this->conn->qstr($catalogo);
        $p = $this->conn->qstr($prm1);
        $qry = "select count(*) from pac_Catalogos where cata_cata = $cata and cata_prm1 = $p";
        $cant = $this->conn->getone($qry);
        return $cant;
    }
    // }}}
    // {{{ lee_l_rfc
    private function lee_l_rfc($rfc) {
        if ($this->cuenta === FALSE) $this->cuenta_l_rfc();
        if ($this->cuenta > 0) {
            $l = $this->conn->qstr($rfc);
            $qry = "select * from pac_l_rfc where rfc_rfc = $l";
            $row= $this->conn->GetRow($qry);
        } else { // NO hay registros de RFC
            // No valida hasta que el SAT publica lista
            $row = array("rfc_rfc"=>$rfc,
                         "rfc_sncf"=>"  ",
                         "rfc_sub"=>"  ");
        }
        return $row;
    }
    // }}}
    // {{{ cuenta_l_rfc
    private function cuenta_l_rfc() {
        $cant= $this->conn->GetOne("select count(*) from pac_l_rfc");
        $this->cuenta = $cant;
    }
    // }}}
    // {{{ cantidad_decimales
    private function cantidad_decimales($impo) {
        @list($ent,$dec) = @explode(".",$impo);
        return strlen($dec);
    }
    // }}}
    // {{{ valida_pedimento
    private function valida_pedimento($pedi) {
        $err = 0;
        $anio = substr($pedi,0,2); // 1 y 2
        $adua = substr($pedi,4,2); // 5 y 6
        $pate = substr($pedi,8,4); // 9 a la 12
        $cant = substr($pedi,-6); // Ultimos 6 digitos
        $this->mensaje = "pedi=$pedi anio=$anio adua=$adua pate=$pate cant=$cant";
        $max = date("y"); $min = $max - 10;
        if ($anio < $min || $anio > $max) {
            $this->mensaje .= ". anio < $min o anio > max=$max";
            return 1;
        }
        $ok = $this->Checa_Catalogo("c_Aduanas", $adua);
        if (!$ok) {
            $this->mensaje .= ". $adua no existe en c_Aduanas";
            return 2;
        }
        $ok = $this->Checa_Catalogo("c_PatenteAduanal", $pate);
        if (!$ok) {
            $this->mensaje .= ". $pate no existe en c_PatenteAduanal";
            return 3;
        }
        $reg = $this->Obten_Catalogo("c_NumPedimentoAduana", $adua, $pate, $anio+2000);
        if (sizeof($reg) == 0) {
            $this->mensaje .= ". no existe en c_NumPedimentoAduana";
            return 4;
        }
        $ultimo = $reg["decimales"];
        if ($cant > $ultimo) {
            $this->mensaje .= ". canr($cant) > ultimo($ultimo)";
            return 5;
        }
        return $err;
    }
    // }}}
    // {{{ Checa_Confirmacion($Confirmacion)
    private function Checa_Confirmacion($Confirmacion) {
        $rs = false;
        $num = $this->conn->qstr($Confirmacion);
        $qry = "select * from pac_confirmacion where llave = $num";
        $rs = $this->conn->getrow($qry);
        if ($rs===FALSE) {
            $this->mensaje = "Confirmacion=$Confirmacion rs===FALSE";
            return 0;
        }
        if (sizeof($rs)==0) {
            $this->mensaje = "Confirmacion=$Confirmacion sizeof(rs)==0";
            return 0;
        }
        $uuid = trim($rs['uuid']);
        if (strlen($uuid)>10) {
            $this->mensaje = "Confirmacion=$Confirmacion uuid=$uuid";
            return 2;
        }
        return 1;
    }
    // }}}
}
