<?php

declare(strict_types=1);

$root     = dirname(__DIR__);
$settings = file_get_contents($root . '/src/settings/YSSettingsTab.php');
$loader   = file_get_contents($root . '/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php');
$failures = 0;

function ys_paynow_ui_assert(bool $condition, string $message, int &$failures): void
{
	if (!$condition) {
		++$failures;
		fwrite(STDERR, "FAIL: {$message}\n");
		return;
	}

	echo "PASS: {$message}\n";
}

ys_paynow_ui_assert(
	str_contains($settings, '.ys-settings-wrap {')
		&& str_contains($settings, 'max-width: 1200px;')
		&& str_contains($settings, 'linear-gradient(135deg, #8fa8b8 0%, #7a95a6 100%)'),
	'PayNow uses the shared 1200px branded settings shell',
	$failures
);

ys_paynow_ui_assert(
	str_contains($settings, '.ys-settings-form {')
		&& str_contains($settings, 'border-radius: 0 0 12px 12px;')
		&& str_contains($settings, '.ys-submit-wrap'),
	'PayNow connects tabs, form content, and footer actions',
	$failures
);

ys_paynow_ui_assert(
	str_contains($loader, 'Version:     2.0.5')
		&& str_contains($loader, 'YSToolboxMenuNormalizer')
		&& !str_contains($loader, 'FeaturesUtil::declare_compatibility'),
	'PayNow vendors Hub Client 2.0.5 without a library-owned HPOS declaration',
	$failures
);

exit($failures > 0 ? 1 : 0);
