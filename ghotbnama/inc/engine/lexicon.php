<?php
/**
 * Persian search lexicon: intent modifiers, soft words, marketplaces, junk
 * signals and strong domains. All words are written in normalised form
 * (see qtb_norm_key: ي→ی، ك→ک، آ→ا، no ZWNJ).
 *
 * The lexicon is generic (any niche). Niche-specific behaviour comes from the
 * data itself (fillers, facets) and from the project's niche description.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Word lists by role. Filterable: add_filter( 'qtb_lexicon', … ).
 */
function qtb_lexicon() {
	static $lex = null;
	if ( null !== $lex ) {
		return $lex;
	}
	$w   = function ( $s ) {
		return array_values( array_filter( preg_split( '/\s*،\s*|\s*,\s*|\n/u', $s ) ) );
	};
	$lex = array(
		// Buying / pricing: the searcher wants to purchase now.
		'transactional' => $w( 'خرید، بخرم، بخریم، خریداری، قیمت، قیمتها، فروش، فروشی، فروشگاه، فروشگاهی، سفارش، ارزان، ارزانترین، ارزون، ارزونترین، تخفیف، تخفیفی، ارسال، رایگان، عمده، عمده فروشی، قسطی، اقساطی، اقساط، نمایندگی، پخش، هزینه، تعرفه، اجاره، کرایه، رزرو، آنلاین، اینترنتی، اصل، اورجینال، تهیه، فروشنده، تولیدی، تولید کننده، بازار، پیش فروش، دست دوم، کارکرده، نو، buy، price، shop، sale، cheap' ),
		// Comparing / choosing: commercial investigation.
		'commercial'    => $w( 'بهترین، برترین، بهتر، مقایسه، تفاوت، فرق، بررسی، نقد، معرفی، لیست، انواع، نوع، مدل، مدلهای، برند، برندهای، مارک، کدام، کدوم، پیشنهاد، راهنمای خرید، مشخصات، مشخصات فنی، ویژگی، ویژگیهای، نظرات، رتبه بندی، محبوبترین، پرفروشترین، جدیدترین، best، vs، review، top' ),
		// Learning / problems: informational.
		'informational' => $w( 'چیست، چیه، چی، چه، چگونه، چطور، چطوری، روش، روشهای، طریقه، طرز، اموزش، نحوه، علت، علل، دلیل، دلایل، چرا، درمان، رفع، راه، راههای، راهنما، نگهداری، مراقبت، تکثیر، قلمه، قلمه زدن، ابیاری، ابدهی، کود، کوددهی، هرس، خواص، فواید، فایده، مضرات، عوارض، معنی، تعریف، کاربرد، کاربردهای، اسامی، اسم، نام، تاریخچه، شرایط، نیاز، چند، چقدر، کجا، کجاست، کی، ایا، میشود، می شود، شدن، کردن، زدن، دادن، گرفتن، داشتن، کنیم، کنم، بگیریم، بدهیم، بکاریم، کاشت، پرورش، ساخت، ساخته، استفاده، how، what، why' ),
		// Wording that does not change the page a query needs (merged away in the clustering key).
		'soft'          => $w( 'خرید، بخرم، قیمت، قیمتها، فروش، فروشی، فروشگاه، سفارش، ارزان، ارزانترین، ارزون، تخفیف، ارسال، انلاین، اینترنتی، انواع، مدل، مدلهای، لیست، روش، روشهای، طریقه، طرز، نحوه، چگونه، چطور، چطوری، اموزش، راهنما، راهنمای، کامل، علت، علل، دلیل، دلایل، چرا، درمان، رفع، چیست، چیه، چی، معرفی، و، با، در، به، از، برای، که، را، این، ان، های، ها، یک، تا، هم، رو، اینکه، ای، بر، جدید، جدیدترین، مناسب' ),
		// Function words: never a topic.
		'stop'          => $w( 'و، در، به، از، با، برای، که، را، این، ان، های، ها، یک، تا، هم، رو، اینکه، ای، بر، یا، اگر، چه، می، هست، است، هستند، شود، کند، کنند، دارد، دارند، بی' ),
		// Comparing / choosing: a different page from the product listing (usually a list article).
		'comparison'    => $w( 'بهترین، برترین، بهتر، مقایسه، تفاوت، فرق، کدام، کدوم، راهنمای خرید، یا، vs، best' ),
		// Services (a service landing, or nothing when the business does not offer it).
		'service'       => $w( 'تعمیر، تعمیرات، تعمیرکار، تعمیرگاه، تعویض، نصب، نصاب، عیب یابی، رفع ایراد، اجاره، کرایه، طراحی، مشاوره، دوره، کلاس، اموزشگاه، خدمات، پشتیبانی، راه اندازی، سرویس کاری' ),
		// Local intent.
		'local'         => $w( 'نزدیک، نزدیکترین، نزدیک من، ادرس، شماره، تلفن، تماس، شعبه، حضوری، تهران، مشهد، اصفهان، شیراز، تبریز، کرج، قم، اهواز، کرمانشاه، رشت، ارومیه، یزد، کرمان، همدان، اراک، زنجان، قزوین، ساری، بندرعباس، گرگان، سنندج، خرم اباد، بوشهر، کیش، قشم، کاشان، بابل، امل، شهریار، اسلامشهر، پردیس، لاهیجان، کرمانشاهی، شمال، جنوب' ),
		// Marketplaces and comparison sites (removed as another platform's navigational query).
		'marketplaces'  => $w( 'دیجیکالا، دیجی کالا، digikala، ترب، torob، باسلام، basalam، دیوار، divar، شیپور، sheypoor، اسنپ شاپ، اسنپ مارکت، snappshop، اسنپ، تپسی شاپ، امازون، amazon، علی بابا، alibaba، علی اکسپرس، aliexpress، ebay، ای بی، ایبی، ایمالز، emalls، بانی مد، banimode، دیجی استایل، digistyle، مدیسه، modiseh، تیمچه، timcheh، اکالا، okala، هایپراستار، هایپر استار، افق کوروش، شهروند، بامیلو، bamilo، کافه بازار، مایکت، ایسام، کمدا' ),
		// Signals of a query that is not about buying or learning this niche (only applied when the niche does not mention them).
		'junk'          => $w( 'دانلود، pdf، apk، اهنگ، موزیک، اهنگهای، ریمیکس، فیلم، سریال، کارتون، انیمیشن، رمان، داستان، فال، تعبیر، خواب، جوک، اس ام اس، پیامک، استوری، والپیپر، پروفایل، کپشن، شعر، دکلمه، متن ادبی، تتو، نقاشی، رنگ امیزی، وکتور، png، psd، لوگو، فونت، بازی، کلیپ، اینستاگرام، تلگرام، روبیکا، ایتا، یوتیوب، اپارات، اسکرین، تست هوش، جدول، سوالات، نمونه سوال' ),
		// Synonyms folded into one word for clustering (left => right).
		'synonyms'      => $w( 'اسامی=اسم، نام=اسم، نامهای=اسم، اسمهای=اسم، تصاویر=عکس، تصویر=عکس، عکسهای=عکس، گلدون=گلدان، ابدهی=ابیاری، مراقبت=نگهداری، ازدیاد=تکثیر، دلایل=علت، دلیل=علت، علل=علت، روشهای=روش، طریقه=روش، طرز=روش، نحوه=روش، فواید=خواص، فایده=خواص، مزایا=خواص، قیمتها=قیمت، ارزون=ارزان، چطوری=چطور، چگونه=چطور' ),
		// Strong Persian domains (difficulty signal).
		'strong'        => $w( 'digikala.com، torob.com، wikipedia.org، fa.wikipedia.org، aparat.com، namnak.com، beytoote.com، chetor.com، hamshahrionline.ir، tasnimnews.com، isna.ir، irna.ir، khabaronline.ir، tebyan.net، niniban.com، modiseh.com، basalam.com، divar.ir، sheypoor.com، youtube.com، instagram.com، snappshop.ir، amazon.com، zoomit.ir، digiato.com، bartarinha.ir، hidoctor.ir، doctoreto.com، paziresh24.com، alibaba.ir، mrbilit.com، filimo.com، namava.ir، faradars.org، mihanwebhost.com، rokna.net، tabnak.ir، yjc.ir، mashreghnews.ir، farsnews.ir' ),
		// Domains that are marketplaces (results are product/category pages of other sellers).
		'market_domains' => $w( 'digikala.com، torob.com، basalam.com، divar.ir، sheypoor.com، snappshop.ir، emalls.ir، banimode.com، digistyle.com، modiseh.com، timcheh.com، amazon.com، aliexpress.com، ebay.com، alibaba.com، okala.com، bamilo.com' ),
		// Q&A / community / video.
		'forum_domains' => $w( 'ninisite.com، niniban.com، quora.com، reddit.com، porsan.ir، porsesh.com، soalcity.ir، kojaro.com' ),
		'video_domains' => $w( 'youtube.com، aparat.com، namasha.com، instagram.com، tiktok.com' ),
	);
	$lex = apply_filters( 'qtb_lexicon', $lex );
	foreach ( $lex as $k => $list ) {
		if ( 'synonyms' === $k ) {
			$map = array();
			foreach ( $list as $pair ) {
				$ab = array_map( 'qtb_norm_key', explode( '=', $pair ) );
				if ( 2 === count( $ab ) && '' !== $ab[0] && '' !== $ab[1] ) {
					$map[ $ab[0] ] = $ab[1];
				}
			}
			$lex[ $k ] = $map;
			continue;
		}
		$domains   = 'strong' === $k || '_domains' === substr( $k, -8 );
		$lex[ $k ] = array_values( array_unique( array_map( $domains ? 'strtolower' : 'qtb_norm_key', $list ) ) );
	}
	return $lex;
}

/**
 * Lexicon list as a set (word => true). Multi-word entries stay as phrases.
 */
function qtb_lex_set( $role ) {
	static $sets = array();
	if ( ! isset( $sets[ $role ] ) ) {
		$sets[ $role ] = array_fill_keys( qtb_lexicon()[ $role ] ?? array(), true );
	}
	return $sets[ $role ];
}

/**
 * Does a normalised phrase contain a lexicon entry (word or multi-word phrase)?
 *
 * @return string The matching entry or ''.
 */
function qtb_lex_hit( $norm_phrase, $role ) {
	$padded = ' ' . $norm_phrase . ' ';
	foreach ( qtb_lexicon()[ $role ] ?? array() as $entry ) {
		if ( '' !== $entry && false !== mb_strpos( $padded, ' ' . $entry . ' ' ) ) {
			return $entry;
		}
	}
	return '';
}

/**
 * Lexicon entries hit by a phrase.
 *
 * @return string[]
 */
function qtb_lex_hits( $norm_phrase, $role ) {
	$padded = ' ' . $norm_phrase . ' ';
	$out    = array();
	foreach ( qtb_lexicon()[ $role ] ?? array() as $entry ) {
		if ( '' !== $entry && false !== mb_strpos( $padded, ' ' . $entry . ' ' ) ) {
			$out[] = $entry;
		}
	}
	return $out;
}

/**
 * Is a domain in a lexicon domain list (suffix match)?
 */
function qtb_domain_in( $domain, $role ) {
	$domain = strtolower( (string) $domain );
	foreach ( qtb_lexicon()[ $role ] ?? array() as $d ) {
		if ( $domain === $d || substr( $domain, -strlen( '.' . $d ) ) === '.' . $d ) {
			return true;
		}
	}
	return false;
}
