<?php
/**
 * Plugin Name:       قطب‌نما — Ghotbnama by Ronagh
 * Description:       قطب‌نما، کیورد کلاسترینگ هوشمند و فروش‌محور: پاک‌سازی کلمات، حذف برند رقبا، خوشه‌بندی بر اساس ۱۰ نتیجه اول گوگل، تشخیص نیت و نوع صفحه، کشف شکاف محتوایی و کنیبالیزیشن، اولویت‌بندی بر اساس بازگشت سرمایه، خروجی کیورد ریسرچ اکسل/گوگل شیت و ساخت تقویم محتوا.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mehdi Ilkhani Nasab
 * License:           GPL-2.0-or-later
 * Text Domain:       ghotbnama
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_VERSION    = '2.0.0';
const QTB_DB_VERSION = '2';
const QTB_CAP        = 'qtb_manage';
const QTB_CAP_REVIEW = 'qtb_review'; // SEO team reviewers: the workshop only.

define( 'QTB_FILE', __FILE__ );
define( 'QTB_DIR', __DIR__ );
define( 'QTB_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'core/helpers',
	'core/jalali',
	'core/settings',
	'core/network',
	'core/install',
	'models/projects',
	'models/data',
	'connectors/google',
	'connectors/sheets',
	'connectors/llm',
	'connectors/serp',
	'connectors/store',
	'engine/lexicon',
	'engine/normalize',
	'engine/clean',
	'engine/precluster',
	'engine/serp-analysis',
	'engine/pages',
	'engine/structure',
	'engine/score',
	'engine/gap',
	'engine/calendar',
	'engine/pipeline',
	'engine/edit',
	'engine/workshop-ai',
	'engine/workshop',
	'export/xlsx',
	'export/xlsx-reader',
	'export/workbooks',
	'export/gsheet',
	'rest',
	'app/ui',
	'app/router',
	'app/actions',
) as $qtb_file ) {
	require_once QTB_DIR . '/inc/' . $qtb_file . '.php';
}
unset( $qtb_file );

register_activation_hook( __FILE__, 'qtb_install' );
register_deactivation_hook( __FILE__, 'qtb_deactivate' );
