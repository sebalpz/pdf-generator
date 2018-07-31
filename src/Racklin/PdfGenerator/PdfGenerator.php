<?php

namespace Racklin\PdfGenerator;
use Illuminate\Support\Facades\Storage;

/**
 * Class PdfGenerator
 *
 * @package Racklin\PdfGenerator
 */
class PdfGenerator
{

    protected $stEngine = null;
    protected $defautFont;
    protected $defaultFontSize;
    protected $defaultFontStyle = '';

    public function __construct()
    {
        $this->stEngine = new \StringTemplate\Engine;

    }


    /**
     * Generate PDF
     *
     * @param $template
     * @param $data
     * @param $name
     * @param $desc 'I' , 'D' , 'F', 'FI' , 'FD'
     */
    public function generate($template, $data, $name = '', $desc = 'I') {

        $templateDir = "";
        if(is_string($template) && is_file($template)) {
            $templateDir = dirname($template);

            $settings = json_decode(file_get_contents($template), true);
        }
        else if(is_object($template) || is_array($template)) {
            $settings = $template;
        }
        else {
            $settings = json_decode($template);
        }
        $tcpdf = $this->initTCPDF($settings);
        foreach ($settings['pages'] as $page) {
            $tcpdf->AddPage();

            // set bacground image
            if (!empty($page['background'])) {
                $img_file = null;
                if(is_file($templateDir . DIRECTORY_SEPARATOR . $page['background'])) {
                    $img_file = $templateDir . DIRECTORY_SEPARATOR . $page['background'];
                }
                else if(is_file($page['background'])) {
                    $img_file = $page['background'];
                }
                if(!is_null($img_file)) {
                    if(mime_content_type($img_file) == 'application/pdf') {
                        $tcpdf->setSourceFile($img_file);
                        if(!empty($page['background_page'])) $pagenr = $page['background_page'];
                        else $pagenr = 1;
                        $tcpdf->tplId = $tcpdf->importPage($pagenr);
                        $tcpdf->useImportedPage($tcpdf->tplId, 0, 0, 210);
                    }
                    else if(strpos(mime_content_type($img_file),'image') !== false) {
                        // get the current page break margin
                        $bMargin = $tcpdf->getBreakMargin();
                        // get current auto-page-break mode
                        $auto_page_break = $tcpdf->getAutoPageBreak();
                        // disable auto-page-break
                        $tcpdf->SetAutoPageBreak(false, 0);
                        // set bacground image
                        $tcpdf->Image($img_file, 0, 0, 210, 297, '', '', '', false, 300, '', false, false, 0);
                        // restore auto-page-break status
                        $tcpdf->SetAutoPageBreak($auto_page_break, $bMargin);
                        // set the starting point for the page content
                        $tcpdf->setPageMark();
                    }
                }

            }

            foreach($page['data'] as $d) {
                if(is_string($d)) {
                    //shortcut
                    $els = explode("|",$d);
                    $d = [];
                    $d['font'] = $this->defaultFont;
                    $d['font-size'] = $this->defaultFontSize;
                    $d['font-style'] = $this->defaultFontStyle;

                    if(sizeof($els) == 1) {
                        $d['x'] = 0;
                        $d['y'] = 0;
                        $d['text'] = $els[0];
                    }
                    else if($els[0] == 'line') {
                        $tcpdf->Line($els[1],$els[2],$els[3],$els[4]);
                    }
                    else if($els[0] == 'box') {
                        $tcpdf->SetXY(intval($els[1])+1, intval($els[2])+1);
                        $tcpdf->SetFontSize(7);
                        $tcpdf->Cell(3, 3, "" ,1);
//                        $tcpdf->Image($this->getXMark(), $els[1], $els[2],7);
                    }
                    else if($els[0] == 'box_x') {
                        $tcpdf->SetXY(intval($els[1])+1, intval($els[2])+1);
                        $tcpdf->SetFontSize(7);
                        $tcpdf->Cell(3, 3, $tcpdf->Image($this->getXMark(), intval($els[1])-1, intval($els[2])-1,7) ,1);
                    }
                    else {
                        $d['x'] = doubleval($els[0]);
                        $d['y'] = doubleval($els[1]);
                        $d['text'] = $els[2];
                        //els[3] ist Schriftstil
                        if(isset($els[3]) && strlen($els[3])) {
                            $d['font-style']=$els[3];
                            $tcpdf->SetFont($d['font'], $d['font-style']);
                        }
                        if(isset($els[4]) && strlen($els[4])) {
                            $d['font-size']=intval($els[4]);
                            $tcpdf->SetFont($d['font'], $d['font-style'], $d['font-size']);
                        }
                        //els[5] ist maximale Breite
                        if(isset($els[5]) && $tcpdf->GetStringWidth($els[2], $d['font'], '', $d['font-size']) > $els[5]) {
                            $rawtext = str_replace(["\r","\n"], ["", "\n "], $els[2]);
                            $paragraphs = explode("\n", $rawtext);
                            $lines = [];
                            foreach ($paragraphs as $paragraph) {
                                $parts = explode(" ", $paragraph);
                                $line = "";
                                $prevpart = "";
                                foreach ($parts as $part) {
                                    $tmpline = $line . $part . " ";
                                    if ($tcpdf->GetStringWidth($tmpline, $d['font'], '', $d['font-size']) > $els[5]) {
                                        if ($prevpart == "") {
                                            $lines = [$els[2]];
                                            break;
                                        }
                                        array_push($lines, trim($line));
                                        $line = $part . " ";
                                    } else $line = $tmpline;
                                    $prevpart = $part;
                                }
                                $line = trim($line);
                                if (strlen($line)) array_push($lines,$line);
                                $d['text'] = implode("\r\n", $lines);
                            }
                        }

                    }
                }
                if (!empty($d['font']) && !empty($d['font-size'])) {
                    $tcpdf->SetFont($d['font'], $d['font-style'], $d['font-size'], '', true);
                }

                // text
                if (!empty($d['text'])) {
                    $txt = $this->renderText($d['text'], $data);
                    $lines = explode("\n", $txt);

                    $offsetY = ceil($d['font-size'] / 2.834 ?: 4);
                    $y = (int)$d['y'];

                    foreach ($lines as $line) {
                        $tcpdf->Text($d['x'], $y, $line);
                        $y += $offsetY;
                    }
                }

                // image
                if (!empty($d['image'])) {
                    $img = $this->renderText($d['image'], $data);

                    $tcpdf->Image($img, $d['x'], $d['y'], $d['w'] ?: 0, $d['h'] ?: 0, '', '', '', false, 300, '', false, false, 0);
                }

                // html
                if (!empty($d['html'])) {
                    $html = $this->renderText($d['html'], $data);
                    $html = str_replace("\n", "<br/>", $html);
                    $tcpdf->writeHTMLCell($d['w'] ?: 0, $d['h'] ?: 0, $d['x'], $d['y'], $html);
                }


            }
        }

        $tcpdf->Output($name, $desc);
    }

    public function getXMark($position=null) {
        $xmarks=array();
        $path=storage_path("app/xmarks/");
        $dh = opendir($path);
        if ($dh) {
            while (($file=readdir($dh)) !== false) {
                if($file==".."||$file[0]==".") continue;
                if(filetype($path.$file)=="dir") continue;
                array_push($xmarks,$path.$file);
            }
            closedir($dh);
        }
        if(!is_null($position) && isset($xmarks[$position])) return $xmarks[$position];
        $position=rand(0,sizeof($xmarks)-1);
        return $xmarks[$position];
    }

    protected  function initTCPDF($settings) {


        $tcpdf = new BaseTCPDF( ($settings['info']['page_orientation'] ?: 'P'), ($settings['info']['page_units'] ?: 'mm'), ($settings['info']['page_format'] ?: 'A4'), true, 'UTF-8', false);

        // set document information
        $tcpdf->SetCreator(PDF_CREATOR);
        $tcpdf->SetAuthor($settings['info']['author']);
        $tcpdf->SetTitle($settings['info']['title']);
        $tcpdf->SetSubject($settings['info']['subject']);
        $tcpdf->SetKeywords($settings['info']['keywords']);

        // set default header data
        //$tcpdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE.' 001', PDF_HEADER_STRING);

        // set header and footer fonts
        //$tcpdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        //$tcpdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // set default monospaced font
        $tcpdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        //set margins
        $tcpdf->SetMargins($settings['info']['left-margin'], $settings['info']['top-margin'], $settings['info']['right-margin']);
        $tcpdf->SetHeaderMargin(0);
        $tcpdf->SetFooterMargin(0);

        //set auto page breaks
        $tcpdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        //set image scale factor
        $tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        // set default font subsetting mode
        $tcpdf->setFontSubsetting(true);

        if(isset($settings['info']['default-font'])) $this->defaultFont = $settings['info']['default-font'];
        else $this->defaultFont = 'helvetica';

        if(isset($settings['info']['default-font-size'])) $this->defaultFontSize = $settings['info']['default-font-size'];
        else $this->defaultFontSize = 16;

        return $tcpdf;
    }

    /**
     * @param $template
     * @param $data
     * @return mixed|string
     */
    protected function renderText($template, $data) {
        $text = $this->stEngine->render($template, $data);
        // empty undefined variable
        $text = preg_replace("/{[\w.]+}/","", $text);
        return $text;
    }

}
