<?php
/**
 * Minimal XLSX writer (no Composer): styled cells, merges, column widths, row
 * heights, frozen panes, right-to-left sheets, hyperlinks, list validations,
 * auto-filter and tab colors.
 *
 * Workbook model (shared with the Google Sheets writer):
 *   sheets: [{
 *     name, rtl, tab (hex), freeze ('A4'), filter ('A1:K1'),
 *     cols: [col => width], heights: [row => points],
 *     cells: [row => [col => value | {v, s, link}]],   rows / cols are 1-based
 *     merges: ['A1:C1'], lists: [{range, values}]
 *   }]
 * Style: {b, i, size, color, bg, h (right|center|left), v (top|center), wrap, border, fmt}
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Column letters from a 1-based index.
 */
function qtb_col( $n ) {
	$s = '';
	for ( $n = (int) $n; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
		$s = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $s;
	}
	return $s;
}

/**
 * A1 reference.
 */
function qtb_ref( $row, $col ) {
	return qtb_col( $col ) . (int) $row;
}

/**
 * Valid, unique sheet name (≤ 31 characters, no []:*?/\).
 */
function qtb_sheet_name( $name, &$used ) {
	$name = trim( preg_replace( '/[\[\]:*?\/\\\\]/u', ' ', (string) $name ) ) ?: 'Sheet';
	$name = mb_substr( $name, 0, 31 );
	$base = $name;
	for ( $i = 2; isset( $used[ mb_strtolower( $name ) ] ); $i++ ) {
		$suffix = ' ' . $i;
		$name   = mb_substr( $base, 0, 31 - mb_strlen( $suffix ) ) . $suffix;
	}
	$used[ mb_strtolower( $name ) ] = true;
	return $name;
}

/**
 * XML escape (and strip characters XML 1.0 does not allow).
 */
function qtb_x( $s ) {
	$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s );
	return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Style registry: style array → cellXfs index.
 */
class QTB_Xlsx_Styles {
	public $fonts   = array();
	public $fills   = array();
	public $borders = array();
	public $numfmts = array();
	public $xfs     = array();
	private $map    = array();

	/**
	 * Constructor with the defaults Excel expects.
	 */
	public function __construct() {
		$this->fonts[]   = '<font><sz val="10"/><name val="Vazirmatn"/><family val="2"/></font>';
		$this->fills[]   = '<fill><patternFill patternType="none"/></fill>';
		$this->fills[]   = '<fill><patternFill patternType="gray125"/></fill>';
		$this->borders[] = '<border><left/><right/><top/><bottom/><diagonal/></border>';
		$this->xfs[]     = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
		$this->map['{}'] = 0;
	}

	/**
	 * Index of a style.
	 */
	public function id( $s ) {
		if ( ! $s ) {
			return 0;
		}
		ksort( $s );
		$key = wp_json_encode( $s );
		if ( isset( $this->map[ $key ] ) ) {
			return $this->map[ $key ];
		}
		$font = '<font>' . ( ! empty( $s['b'] ) ? '<b/>' : '' ) . ( ! empty( $s['i'] ) ? '<i/>' : '' ) . ( ! empty( $s['u'] ) ? '<u/>' : '' )
			. '<sz val="' . (float) ( $s['size'] ?? 10 ) . '"/>' . ( ! empty( $s['color'] ) ? '<color rgb="FF' . strtoupper( $s['color'] ) . '"/>' : '' )
			. '<name val="Vazirmatn"/><family val="2"/></font>';
		$fi   = $this->add( $this->fonts, $font );
		$fl   = 0;
		if ( ! empty( $s['bg'] ) ) {
			$fl = $this->add( $this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper( $s['bg'] ) . '"/><bgColor indexed="64"/></patternFill></fill>' );
		}
		$bd = 0;
		if ( ! empty( $s['border'] ) ) {
			$c  = '<color rgb="FF' . strtoupper( is_string( $s['border'] ) ? $s['border'] : 'D9D9D9' ) . '"/>';
			$bd = $this->add( $this->borders, '<border><left style="thin">' . $c . '</left><right style="thin">' . $c . '</right><top style="thin">' . $c . '</top><bottom style="thin">' . $c . '</bottom><diagonal/></border>' );
		}
		$nf = 0;
		if ( ! empty( $s['fmt'] ) ) {
			if ( '#,##0' === $s['fmt'] ) {
				$nf = 3;
			} elseif ( '0%' === $s['fmt'] ) {
				$nf = 9;
			} else {
				$nf = 164 + count( $this->numfmts );
				$this->numfmts[ $nf ] = $s['fmt'];
			}
		}
		$align = '';
		if ( ! empty( $s['h'] ) || ! empty( $s['v'] ) || ! empty( $s['wrap'] ) ) {
			$align = '<alignment' . ( ! empty( $s['h'] ) ? ' horizontal="' . $s['h'] . '"' : '' ) . ' vertical="' . ( $s['v'] ?? 'center' ) . '"' . ( ! empty( $s['wrap'] ) ? ' wrapText="1"' : '' ) . ' readingOrder="2"/>';
		}
		$xf = '<xf numFmtId="' . $nf . '" fontId="' . $fi . '" fillId="' . $fl . '" borderId="' . $bd . '" xfId="0"' . ( $nf ? ' applyNumberFormat="1"' : '' ) . ' applyFont="1"' . ( $fl ? ' applyFill="1"' : '' ) . ( $bd ? ' applyBorder="1"' : '' ) . ( $align ? ' applyAlignment="1">' . $align . '</xf>' : '/>' );
		$this->xfs[]       = $xf;
		$this->map[ $key ] = count( $this->xfs ) - 1;
		return $this->map[ $key ];
	}

	/**
	 * Add to a list (deduplicated) and return the index.
	 */
	private function add( &$list, $xml ) {
		$i = array_search( $xml, $list, true );
		if ( false === $i ) {
			$list[] = $xml;
			$i      = count( $list ) - 1;
		}
		return $i;
	}

	/**
	 * styles.xml
	 */
	public function xml() {
		$nf = '';
		if ( $this->numfmts ) {
			$nf = '<numFmts count="' . count( $this->numfmts ) . '">';
			foreach ( $this->numfmts as $id => $code ) {
				$nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . qtb_x( $code ) . '"/>';
			}
			$nf .= '</numFmts>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $nf
			. '<fonts count="' . count( $this->fonts ) . '">' . implode( '', $this->fonts ) . '</fonts>'
			. '<fills count="' . count( $this->fills ) . '">' . implode( '', $this->fills ) . '</fills>'
			. '<borders count="' . count( $this->borders ) . '">' . implode( '', $this->borders ) . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count( $this->xfs ) . '">' . implode( '', $this->xfs ) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}
}

/**
 * Write a workbook model to an .xlsx file.
 *
 * @return string|WP_Error file path
 */
function qtb_xlsx_write( $book, $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'qtb_zip', 'افزونه ZipArchive در PHP سرور فعال نیست؛ خروجی اکسل ساخته نمی‌شود (از خروجی گوگل شیت یا CSV استفاده کنید).' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'qtb_zip', 'ساخت فایل اکسل ناموفق بود (دسترسی نوشتن پوشه uploads را بررسی کنید).' );
	}
	$styles = new QTB_Xlsx_Styles();
	$used   = array();
	$sheets = array();
	foreach ( $book['sheets'] as $i => $sh ) {
		$name     = qtb_sheet_name( $sh['name'], $used );
		$sheets[] = array( 'name' => $name, 'file' => 'sheet' . ( $i + 1 ) . '.xml', 'rid' => 'rId' . ( $i + 1 ) );
		list( $xml, $rels ) = qtb_xlsx_sheet_xml( $sh, $styles );
		$zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', $xml );
		if ( $rels ) {
			$zip->addFromString( 'xl/worksheets/_rels/sheet' . ( $i + 1 ) . '.xml.rels', $rels );
		}
	}
	$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
		. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
		. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
	foreach ( $sheets as $s ) {
		$ct .= '<Override PartName="/xl/worksheets/' . $s['file'] . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
	}
	$ct .= '</Types>';
	$zip->addFromString( '[Content_Types].xml', $ct );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' );
	$zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . qtb_x( $book['title'] ?? '' ) . '</dc:title><dc:creator>Ghotbnama by Ronagh</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>' );
	$zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Ghotbnama</Application></Properties>' );
	$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
	$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	$defs = '';
	foreach ( $sheets as $i => $s ) {
		$wb   .= '<sheet name="' . qtb_x( $s['name'] ) . '" sheetId="' . ( $i + 1 ) . '" r:id="' . $s['rid'] . '"/>';
		$rels .= '<Relationship Id="' . $s['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . $s['file'] . '"/>';
		if ( ! empty( $book['sheets'][ $i ]['filter'] ) ) {
			$defs .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . qtb_x( "'" . str_replace( "'", "''", $s['name'] ) . "'!" . qtb_abs_range( qtb_filter_range( $book['sheets'][ $i ] ) ) ) . '</definedName>';
		}
	}
	$wb   .= '</sheets>' . ( $defs ? '<definedNames>' . $defs . '</definedNames>' : '' ) . '</workbook>';
	$rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
	$zip->addFromString( 'xl/workbook.xml', $wb );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
	$zip->addFromString( 'xl/styles.xml', $styles->xml() );
	$zip->close();
	return $path;
}

/**
 * Filter range of a sheet: header row through the last row.
 */
function qtb_filter_range( $sh ) {
	if ( ! preg_match( '/^([A-Z]+)(\d+):([A-Z]+)\d+$/', (string) $sh['filter'], $m ) ) {
		return (string) $sh['filter'];
	}
	$last = $sh['cells'] ? max( array_keys( $sh['cells'] ) ) : (int) $m[2];
	return $m[1] . $m[2] . ':' . $m[3] . max( (int) $m[2], $last );
}

/**
 * $A$1:$B$9 form.
 */
function qtb_abs_range( $range ) {
	return preg_replace( '/([A-Z]+)(\d+)/', '\$$1\$$2', $range );
}

/**
 * One worksheet XML (+ its relationships for hyperlinks).
 *
 * @return array [xml, rels]
 */
function qtb_xlsx_sheet_xml( $sh, $styles ) {
	$x     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
	$x    .= '<sheetPr>' . ( ! empty( $sh['tab'] ) ? '<tabColor rgb="FF' . strtoupper( $sh['tab'] ) . '"/>' : '' ) . '</sheetPr>';
	$x    .= '<sheetViews><sheetView workbookViewId="0"' . ( ! isset( $sh['rtl'] ) || $sh['rtl'] ? ' rightToLeft="1"' : '' ) . ( ! empty( $sh['grid'] ) ? '' : ' showGridLines="0"' ) . '>';
	if ( ! empty( $sh['freeze'] ) && preg_match( '/^([A-Z]+)(\d+)$/', $sh['freeze'], $fm ) ) {
		$col  = 0;
		foreach ( str_split( $fm[1] ) as $ch ) {
			$col = $col * 26 + ( ord( $ch ) - 64 );
		}
		$ys   = (int) $fm[2] - 1;
		$xs   = $col - 1;
		$pane = $ys && $xs ? 'bottomRight' : ( $ys ? 'bottomLeft' : 'topRight' );
		$x   .= '<pane' . ( $xs ? ' xSplit="' . $xs . '"' : '' ) . ( $ys ? ' ySplit="' . $ys . '"' : '' ) . ' topLeftCell="' . $sh['freeze'] . '" activePane="' . $pane . '" state="frozen"/>';
	}
	$x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="18" baseColWidth="10"/>';
	if ( ! empty( $sh['cols'] ) ) {
		ksort( $sh['cols'] );
		$x .= '<cols>';
		foreach ( $sh['cols'] as $c => $w ) {
			$x .= '<col min="' . (int) $c . '" max="' . (int) $c . '" width="' . (float) $w . '" customWidth="1"/>';
		}
		$x .= '</cols>';
	}
	$x    .= '<sheetData>';
	$links = array();
	$cells = (array) ( $sh['cells'] ?? array() );
	ksort( $cells );
	foreach ( $cells as $r => $row ) {
		ksort( $row );
		$h  = $sh['heights'][ $r ] ?? null;
		$x .= '<row r="' . (int) $r . '"' . ( $h ? ' ht="' . (float) $h . '" customHeight="1"' : '' ) . '>';
		foreach ( $row as $c => $cell ) {
			$v   = is_array( $cell ) ? ( $cell['v'] ?? null ) : $cell;
			$sid = is_array( $cell ) ? $styles->id( (array) ( $cell['s'] ?? array() ) ) : 0;
			$ref = qtb_ref( $r, $c );
			if ( is_array( $cell ) && ! empty( $cell['link'] ) && preg_match( '#^https?://#', $cell['link'] ) ) {
				$links[ $ref ] = $cell['link'];
			}
			if ( null === $v || '' === $v ) {
				$x .= '<c r="' . $ref . '"' . ( $sid ? ' s="' . $sid . '"' : '' ) . '/>';
			} elseif ( is_bool( $v ) ) {
				$x .= '<c r="' . $ref . '" t="b"' . ( $sid ? ' s="' . $sid . '"' : '' ) . '><v>' . ( $v ? 1 : 0 ) . '</v></c>';
			} elseif ( is_int( $v ) || is_float( $v ) ) {
				$x .= '<c r="' . $ref . '"' . ( $sid ? ' s="' . $sid . '"' : '' ) . '><v>' . ( is_float( $v ) ? rtrim( rtrim( sprintf( '%.6F', $v ), '0' ), '.' ) : $v ) . '</v></c>';
			} else {
				$x .= '<c r="' . $ref . '" t="inlineStr"' . ( $sid ? ' s="' . $sid . '"' : '' ) . '><is><t xml:space="preserve">' . qtb_x( $v ) . '</t></is></c>';
			}
		}
		$x .= '</row>';
	}
	$x .= '</sheetData>';
	if ( ! empty( $sh['filter'] ) ) {
		$x .= '<autoFilter ref="' . qtb_filter_range( $sh ) . '"/>';
	}
	if ( ! empty( $sh['merges'] ) ) {
		$x .= '<mergeCells count="' . count( $sh['merges'] ) . '">';
		foreach ( array_unique( $sh['merges'] ) as $m ) {
			$x .= '<mergeCell ref="' . $m . '"/>';
		}
		$x .= '</mergeCells>';
	}
	if ( ! empty( $sh['lists'] ) ) {
		$x .= '<dataValidations count="' . count( $sh['lists'] ) . '">';
		foreach ( $sh['lists'] as $dv ) {
			$x .= '<dataValidation type="list" allowBlank="1" showDropDown="0" sqref="' . $dv['range'] . '"><formula1>"' . qtb_x( implode( ',', $dv['values'] ) ) . '"</formula1></dataValidation>';
		}
		$x .= '</dataValidations>';
	}
	$rels = '';
	if ( $links ) {
		$x    .= '<hyperlinks>';
		$rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		$n     = 0;
		foreach ( $links as $ref => $url ) {
			++$n;
			$x    .= '<hyperlink ref="' . $ref . '" r:id="rL' . $n . '"/>';
			$rels .= '<Relationship Id="rL' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . qtb_x( $url ) . '" TargetMode="External"/>';
		}
		$x    .= '</hyperlinks>';
		$rels .= '</Relationships>';
	}
	$x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
	return array( $x, $rels );
}
