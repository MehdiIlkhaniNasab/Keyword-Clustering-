<?php
/**
 * AI tasks of the clustering workshop. Every prompt carries the project memory:
 * business profile, project rules, the reviewer's recent corrections and the
 * clusters already approved — so decisions stay consistent from topic to topic.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Triage reasons (keep / remove / park).
 */
function qtb_triage_reasons() {
	return array(
		'ok'          => 'مرتبط و ارزشمند',
		'competitor'  => 'برند یا فروشگاه رقیب',
		'not_sold'    => 'محصول یا خدمتی که نمی‌فروشید',
		'irrelevant'  => 'نامرتبط با کسب‌وکار',
		'navigational' => 'جستجوی سایت یا اپ دیگر',
		'junk'        => 'بی‌ارزش (دانلود، سرگرمی…)',
		'low_value'   => 'ارزش فروش کم',
		'unsure'      => 'نیاز به تصمیم شما',
		'rule'        => 'قانون پروژه',
		'manual'      => 'تصمیم دستی',
	);
}

/**
 * Project memory as prompt text.
 *
 * @param array $ws Workshop state.
 */
function qtb_ws_memory_text( $project, $ws, $topic_id = null ) {
	$p   = qtb_project_profile( $project );
	$out = "## کسب‌وکار\n" . $project['name'] . ' (' . $project['site_url'] . ")\n";
	$out .= 'حوزه: ' . ( $p['niche'] ?: '—' ) . "\n";
	if ( '' !== $p['sells'] ) {
		$out .= 'می‌فروشد: ' . $p['sells'] . "\n";
	}
	if ( '' !== $p['not_sells'] ) {
		$out .= 'نمی‌فروشد: ' . $p['not_sells'] . "\n";
	}
	if ( $p['services'] ) {
		$out .= 'خدمات: ' . implode( '، ', $p['services'] ) . "\n";
	}
	$out .= 'شعبه حضوری در: ' . ( $p['locations'] ? implode( '، ', $p['locations'] ) : 'ندارد (فقط فروش آنلاین)' ) . "\n";
	if ( '' !== $p['priorities'] ) {
		$out .= 'اولویت فروش: ' . $p['priorities'] . "\n";
	}
	if ( $p['brands'] ) {
		$out .= 'برند خودمان: ' . implode( '، ', $p['brands'] ) . "\n";
	}
	if ( $p['competitors'] ) {
		$out .= 'رقبا: ' . implode( '، ', $p['competitors'] ) . "\n";
	}
	$inv = qtb_data_get( $project['id'], 'inventory', array() );
	if ( ! empty( $inv['items'] ) ) {
		$cats = array();
		foreach ( $inv['items'] as $it ) {
			if ( 'category' === $it['type'] && count( $cats ) < 60 ) {
				$cats[] = $it['title'] . ( ! empty( $it['count'] ) ? ' (' . $it['count'] . ')' : '' );
			}
		}
		if ( $cats ) {
			$out .= 'دسته‌ها و برندهای سایت (تعداد محصول): ' . implode( '، ', $cats ) . "\n";
		}
	}
	$rules = array();
	foreach ( (array) ( $ws['rules'] ?? array() ) as $r ) {
		$rules[] = '- ' . $r['text'];
	}
	if ( $rules ) {
		$out .= "\n## قوانین پروژه (از نظر مدیر سئو؛ همیشه رعایت کن)\n" . implode( "\n", array_slice( $rules, -40 ) ) . "\n";
	}
	$corr = array();
	foreach ( array_slice( (array) ( $ws['corrections'] ?? array() ), -15 ) as $c ) {
		$corr[] = sprintf( '- «%s»: از «%s» به «%s»', $c['k'], $c['from'], $c['to'] );
	}
	if ( $corr ) {
		$out .= "\n## اصلاح‌های اخیر مدیر سئو (الگو بگیر)\n" . implode( "\n", $corr ) . "\n";
	}
	$approved = array();
	foreach ( (array) ( $ws['topics'] ?? array() ) as $t ) {
		if ( 'approved' !== $t['status'] || $t['id'] === $topic_id ) {
			continue;
		}
		foreach ( $t['clusters'] as $c ) {
			if ( count( $approved ) < 80 ) {
				$approved[] = $c['name'] . ' [' . qtb_page_label( $c ) . ']';
			}
		}
	}
	if ( $approved ) {
		$out .= "\n## کلاسترهای تأییدشده موضوع‌های دیگر\n" . implode( '، ', $approved ) . "\n";
	}
	return $out;
}

/**
 * Triage a batch of keywords.
 *
 * @param array $batch [{i, k, v}]
 * @return array|WP_Error i → {label, reason, note, brand, intent}
 */
function qtb_ai_triage_batch( $project, $ws, $batch ) {
	$lines = array();
	foreach ( $batch as $kw ) {
		$lines[] = $kw['i'] . '|' . $kw['k'] . '|' . $kw['v'];
	}
	$system = 'تو مدیر ارشد سئو و کیورد ریسرچ فارسی هستی و فهرست کلمات یک پروژه را برای ساختار سایت و فروش غربال می‌کنی. فقط کلماتی بمانند که این کسب‌وکار می‌تواند با یک صفحه (محصول، دسته، لندینگ یا مقاله) از آن‌ها مشتری بگیرد.';
	$user   = qtb_ws_memory_text( $project, $ws ) .
		"\n## کار\nبرای هر کلمه (شناسه|کلمه|حجم) بده:\n" .
		"- label: keep (نگه دار)، remove (حذف) یا unsure (مطمئن نیستم؛ مدیر تصمیم می‌گیرد)\n" .
		"- reason: ok | competitor (برند/فروشگاه/سایت رقیب) | not_sold (محصول یا خدمتی که این کسب‌وکار ندارد) | irrelevant (معنای دیگر یا بی‌ربط) | navigational | junk | low_value\n" .
		"- brand: اگر کلمه نام برند تولیدکننده یا فروشگاه دارد، همان نام (وگرنه خالی)\n" .
		"- intent: t خرید، c مقایسه/انتخاب، i آموزشی، n برند خودمان، l محلی\n" .
		"قواعد: برند تولیدکننده‌ای که این کسب‌وکار می‌فروشد رقیب نیست. شهرها حذف نمی‌شوند مگر قوانین بگوید. در شک unsure بده، نه remove. note حداکثر ۸ کلمه.\n\n" .
		"## کلمات\n" . implode( "\n", $lines );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items' ),
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'label', 'reason', 'brand', 'intent', 'note' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'label'  => array( 'type' => 'string', 'enum' => array( 'keep', 'remove', 'unsure' ) ),
						'reason' => array( 'type' => 'string', 'enum' => array( 'ok', 'competitor', 'not_sold', 'irrelevant', 'navigational', 'junk', 'low_value' ) ),
						'brand'  => array( 'type' => 'string' ),
						'intent' => array( 'type' => 'string', 'enum' => array( 't', 'c', 'i', 'n', 'l' ) ),
						'note'   => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'light', 'max_tokens' => 9000, 'timeout' => 200 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_fill_keys( array_column( $batch, 'i' ), true );
	$out   = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$id = (int) ( $it['id'] ?? -1 );
		if ( isset( $valid[ $id ] ) ) {
			$out[ $id ] = array(
				'label'  => (string) $it['label'],
				'reason' => (string) $it['reason'],
				'brand'  => qtb_norm_key( (string) $it['brand'] ),
				'intent' => (string) $it['intent'],
				'note'   => qtb_mb_cut( (string) $it['note'], 80 ),
			);
		}
	}
	return $out;
}

/**
 * Topic segmentation of keyword groups.
 *
 * @param array $groups [{gid, head, vol, more: string[]}]
 * @param array $known  Topic names already used.
 * @return array|WP_Error gid → topic name
 */
function qtb_ai_topics_batch( $project, $ws, $groups, $known ) {
	$lines = array();
	foreach ( $groups as $g ) {
		$lines[] = sprintf( '%d | %s | %d | %s', $g['gid'], $g['head'], $g['vol'], implode( '، ', array_slice( $g['more'], 0, 4 ) ) );
	}
	$system = 'تو معمار اطلاعات و مدیر ارشد سئو هستی. گروه‌های کلمات را به «موضوع»‌ها تقسیم می‌کنی؛ هر موضوع یک خط محصول، یک خدمت یا یک حوزه محتوایی است که کلماتش باید کنار هم بررسی شوند تا معلوم شود کدام در یک صفحه‌اند.';
	$user   = qtb_ws_memory_text( $project, $ws ) .
		"\n## موضوع‌های موجود (اگر مناسب‌اند همین نام‌ها را تکرار کن)\n" . ( $known ? implode( '، ', array_slice( $known, 0, 80 ) ) : '—' ) .
		"\n\n## کار\nبرای هر گروه (شناسه | کلمه اصلی | حجم | چند کلمه دیگر) نام موضوع را بده.\n" .
		"قواعد حرفه‌ای:\n" .
		"- موضوع بر اساس «چیزی که مشتری می‌خرد یا یاد می‌گیرد» است، نه کلمه مشترک. مثال: «اینترنت گیمینگ»، «مودم گیمینگ»، «بهترین مودم برای بازی» و «اینترنت نامحدود» شاید کلمه مشترک داشته باشند ولی موضوع‌شان فرق می‌کند: «مودم و روتر» (کالا) در برابر «سرویس اینترنت» (خدمت). برعکس «مودم نامحدود» و «مودم سیم‌کارتی» هر دو «مودم» هستند.\n" .
		"- کلمات آموزشی همان محصول (نگهداری، نصب، مقایسه) در همان موضوع محصول بمانند، چون مقاله‌ها پشتیبان صفحات فروش‌اند.\n" .
		"- موضوع‌ها نه خیلی ریز (یک کلمه) و نه خیلی کلی («همه محصولات») باشند؛ معمولاً ۲۰ تا ۲۵۰ کلمه.\n" .
		"- نام موضوع ۱ تا ۳ کلمه، فارسی و همان اصطلاح بازار.\n\n## گروه‌ها\n" . implode( "\n", $lines );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items' ),
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'topic' ),
					'properties'           => array(
						'id'    => array( 'type' => 'integer' ),
						'topic' => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 7000, 'timeout' => 240 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$t = qtb_mb_cut( trim( (string) ( $it['topic'] ?? '' ) ), 40 );
		if ( '' !== $t ) {
			$out[ (int) $it['id'] ] = qtb_display( $t );
		}
	}
	return $out;
}

/**
 * Keyword review: does each keyword belong to its proposed cluster?
 *
 * @param array $items    [{i, k, v, cid, conf, shared, alt, alt_shared, titles, types}]
 * @param array $clusters [{cid, name, page, members: string[], size}]
 * @param bool  $single   One keyword per request (richer context per keyword).
 * @return array|WP_Error i → {action, to, conf, reason}
 */
function qtb_ai_review_batch( $project, $ws, $topic, $items, $clusters, $single = false ) {
	$cl = array();
	foreach ( $clusters as $c ) {
		$cl[] = sprintf( 'C%d «%s» [%s] %s کلمه: %s', $c['cid'], $c['name'], $c['page'], $c['size'], implode( '، ', array_slice( $c['members'], 0, $single ? 8 : 4 ) ) );
	}
	$kl = array();
	foreach ( $items as $it ) {
		$kl[] = sprintf(
			"#%d «%s» (حجم %s) → پیشنهاد سرپ: C%d (%s آدرس مشترک با سر کلاستر، اطمینان %s)%s\n   عنوان‌های گوگل: %s",
			$it['i'],
			$it['k'],
			$it['v'],
			$it['cid'],
			$it['shared'] >= 0 ? $it['shared'] : '؟',
			$it['conf'],
			null !== $it['alt'] ? sprintf( '؛ کاندید دوم C%d با %s آدرس مشترک', $it['alt'], $it['alt_shared'] ) : '',
			$it['titles'] ? implode( ' | ', array_slice( $it['titles'], 0, $single ? 6 : 3 ) ) : '(بدون داده گوگل)'
		);
	}
	$system = 'تو بازبین ارشد کیورد کلاسترینگ هستی. معیار طلایی: دو کلمه فقط وقتی یک صفحه‌اند که گوگل برایشان نتایج مشترک (۴ آدرس از ۱۰ یا بیشتر) نشان دهد و نیت کاربر یکی باشد. برند، شهر، خدمت (نصب، تعویض، تعمیر)، «بهترین/مقایسه» و ویژگی مهم محصول معمولاً نیت را عوض می‌کنند. تصمیم سرپ را فقط با دلیل روشن عوض کن.';
	$user   = qtb_ws_memory_text( $project, $ws, $topic['id'] ) .
		"\n## موضوع: " . $topic['name'] . "\n### کلاسترهای این موضوع\n" . implode( "\n", $cl ) .
		"\n\n### کلمات برای بازبینی\n" . implode( "\n", $kl ) .
		"\n\n## کار\nبرای هر کلمه یک تصمیم: keep (همان کلاستر درست است)، move (به کلاستر دیگر: to = شماره کلاستر بدون C)، new (کلاستر جدید لازم است: to = نام کلاستر جدید)، remove (به درد این کسب‌وکار نمی‌خورد)، park (فعلاً کنار بگذار). conf: 1 کم، 2 متوسط، 3 زیاد. reason حداکثر ۱۲ کلمه و مشخص.";
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items' ),
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'action', 'to', 'conf', 'reason' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'action' => array( 'type' => 'string', 'enum' => array( 'keep', 'move', 'new', 'remove', 'park' ) ),
						'to'     => array( 'type' => 'string' ),
						'conf'   => array( 'type' => 'integer' ),
						'reason' => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'light', 'max_tokens' => $single ? 600 : 6000, 'timeout' => 180 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_fill_keys( array_column( $items, 'i' ), true );
	$out   = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$id = (int) ( $it['id'] ?? -1 );
		if ( isset( $valid[ $id ] ) ) {
			$out[ $id ] = array(
				'action' => (string) $it['action'],
				'to'     => trim( (string) $it['to'] ),
				'conf'   => max( 1, min( 3, (int) $it['conf'] ) ),
				'reason' => qtb_mb_cut( (string) $it['reason'], 120 ),
			);
		}
	}
	return $out;
}

/**
 * Cluster review: name, page type and section of each cluster of a topic.
 *
 * @param array $clusters [{cid, name, size, vol, page, archive, reason, members, types, products}]
 * @return array|WP_Error cid → {name, page, archive, section, reason}
 */
function qtb_ai_review_clusters( $project, $ws, $topic, $clusters ) {
	$lines = array();
	foreach ( $clusters as $c ) {
		$lines[] = sprintf(
			'C%d «%s» (%s کلمه، حجم %s) پیشنهاد فعلی: %s — %s | نوع نتایج گوگل: %s | محصول منطبق در سایت: %s | کلمات: %s',
			$c['cid'],
			$c['name'],
			$c['size'],
			$c['vol'],
			$c['page'] . ( $c['archive'] ? ':' . $c['archive'] : '' ),
			$c['reason'],
			$c['types'] ?: '—',
			$c['products'],
			implode( '، ', array_slice( $c['members'], 0, 6 ) )
		);
	}
	$system = 'تو مدیر ارشد سئو هستی و برای هر کلاستر نوع صفحه را با منطق فروش و هزینه تعیین می‌کنی. چهار نوع: landing (صفحه اختصاصی: خدمات، شعبه محلی، یا موضوعی که ساختار فروشگاه برایش نیست)، archive (دسته، برند، برچسب یا ویژگی فروشگاه)، product (یک محصول)، article (مقاله آموزشی یا مقایسه‌ای). اصل: وقتی دسته محصول کار را انجام می‌دهد لندینگ جدا نساز؛ وقتی یک محصول کافی است دسته نساز؛ کلمات آموزشی و «بهترین…» مقاله‌اند مگر گوگل صفحات فروشگاهی نشان دهد.';
	$user   = qtb_ws_memory_text( $project, $ws, $topic['id'] ) .
		"\n## موضوع: " . $topic['name'] . "\n" . implode( "\n", $lines ) .
		"\n\n## کار\nبرای هر کلاستر: name (کلمه اصلی صفحه، معمولاً پرحجم‌ترین کلمه)، page (landing|archive|product|article)، archive (category|brand|tag|attribute یا خالی)، section (نام بخش در شیت، مثل «صفحات اصلی»، «انواع»، «برندها»، «نگهداری»)، reason (یک جمله؛ اگر با پیشنهاد فعلی موافقی همان را کوتاه تأیید کن).";
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items' ),
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'name', 'page', 'archive', 'section', 'reason' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'name'    => array( 'type' => 'string' ),
						'page'    => array( 'type' => 'string', 'enum' => array( 'landing', 'archive', 'product', 'article' ) ),
						'archive' => array( 'type' => 'string' ),
						'section' => array( 'type' => 'string' ),
						'reason'  => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 8000, 'timeout' => 240 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$out[ (int) $it['id'] ] = array(
			'name'    => qtb_mb_cut( qtb_display( (string) $it['name'] ), 80 ),
			'page'    => (string) $it['page'],
			'archive' => isset( qtb_archive_types()[ $it['archive'] ] ) ? (string) $it['archive'] : '',
			'section' => qtb_mb_cut( qtb_display( (string) $it['section'] ), 40 ),
			'reason'  => qtb_mb_cut( (string) $it['reason'], 220 ),
		);
	}
	return $out;
}

/**
 * Turn a reviewer comment into project rules.
 *
 * @return array|WP_Error {reply, rules: [{kind, match, page, archive, text}]}
 */
function qtb_ai_comment_rules( $project, $ws, $topic, $text ) {
	$system = 'تو دستیار مدیر سئو هستی. نظر مدیر درباره خوشه‌بندی را به قوانین دقیق و قابل اجرا تبدیل کن تا روی این موضوع و موضوع‌های بعدی اعمال شود.';
	$user   = qtb_ws_memory_text( $project, $ws ) .
		"\n## موضوع فعلی: " . ( $topic ? $topic['name'] : '—' ) .
		"\n## نظر مدیر\n" . $text .
		"\n\n## کار\nقوانین را بده. kind یکی از: remove (کلمات شامل match حذف شوند)، keep (نگه داشته شوند)، park (کنار گذاشته شوند)، page (کلاسترهایی که کلمه‌ای شامل match دارند این نوع صفحه شوند: page و archive)، note (دستور کلی برای بازبینی هوش مصنوعی؛ match خالی). match یک کلمه یا عبارت کوتاه دقیقاً به همان شکل در کلمات. text همان قانون به زبان ساده. reply: یک جمله که بگویی چه فهمیدی.";
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'reply', 'rules' ),
		'properties'           => array(
			'reply' => array( 'type' => 'string' ),
			'rules' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'kind', 'match', 'page', 'archive', 'text' ),
					'properties'           => array(
						'kind'    => array( 'type' => 'string', 'enum' => array( 'remove', 'keep', 'park', 'page', 'note' ) ),
						'match'   => array( 'type' => 'string' ),
						'page'    => array( 'type' => 'string' ),
						'archive' => array( 'type' => 'string' ),
						'text'    => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	return qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 2500, 'timeout' => 120 ) );
}

/**
 * Cut a string to a length (multibyte).
 */
function qtb_mb_cut( $s, $n ) {
	$s = trim( (string) $s );
	return mb_strlen( $s ) > $n ? mb_substr( $s, 0, $n - 1 ) . '…' : $s;
}
