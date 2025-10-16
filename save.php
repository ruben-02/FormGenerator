<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

$form_name = $_POST['form_name'];
$prompt = $_POST['prompt'];
// datasource passed from UI: 'sqlite' or 'mysql'
$datasource = $_POST['datasource'] ?? 'sqlite';
if ($datasource !== 'mysql') $datasource = 'sqlite';

// Original generated form HTML (already sanitized in generate.php)
$form_code = $_POST['form_code'];

// Whitelist-based sanitizer: remove disallowed tags and attributes
function sanitize_form_html($html) {
	libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	// Wrap fragment so DOMDocument can parse it
	$doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>');
	$xpath = new DOMXPath($doc);

	// Remove fully disallowed tags entirely
	$disallowedTags = ['script','iframe','style','object','embed','link','meta'];
	foreach ($disallowedTags as $tag) {
		$nodes = $xpath->query('//' . $tag);
		foreach ($nodes as $n) {
			if ($n->parentNode) $n->parentNode->removeChild($n);
		}
	}

	// Allowed tags and attributes
	$allowedTags = ['form','input','textarea','select','label','option','fieldset','legend','button','div','span'];
	$allowedAttrs = ['id','name','class','type','value','pattern','inputmode','maxlength','placeholder','rows','cols','for','checked','selected','multiple','accept'];

	$root = $doc->getElementById('root');
	if ($root) {
		$nodes = $xpath->query('.//*', $root);
		foreach ($nodes as $node) {
			if (!($node instanceof DOMElement)) continue;
			$tag = strtolower($node->tagName);

			// If tag not allowed, remove tag but keep its children
			if (!in_array($tag, $allowedTags, true)) {
				while ($node->firstChild) {
					$node->parentNode->insertBefore($node->firstChild, $node);
				}
				$node->parentNode->removeChild($node);
				continue;
			}

			// Remove disallowed attributes (event handlers, style, and anything not in whitelist)
			$attrs = [];
			foreach ($node->attributes as $a) {
				$attrs[] = $a->name;
			}
			foreach ($attrs as $a) {
				if (preg_match('/^on/i', $a) || strtolower($a) === 'style' || !in_array(strtolower($a), $allowedAttrs, true)) {
					$node->removeAttribute($a);
				}
			}
		}
	}

	// Extract inner HTML of #root
	$out = '';
	if ($root) {
		foreach ($root->childNodes as $child) {
			$out .= $doc->saveHTML($child);
		}
	}
	libxml_clear_errors();
	return $out;
}

// Transform the generated form HTML to inject predictable classes/structure
// so the saved form will render with the same style as the preview.
function transform_form_html($html) {
	libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	// Wrap so we can parse fragments
	$doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>');
	$xpath = new DOMXPath($doc);

	// Add classes to input/select/textarea
	foreach ($xpath->query('//*[@id="root"]//input|//*[@id="root"]//select|//*[@id="root"]//textarea') as $node) {
		if ($node instanceof DOMElement) {
			$existing = $node->getAttribute('class');
			$classes = array_filter(array_map('trim', explode(' ', $existing)));
			if (!in_array('fp-input', $classes)) $classes[] = 'fp-input';
			$node->setAttribute('class', implode(' ', $classes));
		}
	}

	// Add class to labels
	foreach ($xpath->query('//*[@id="root"]//label') as $label) {
		if ($label instanceof DOMElement) {
			$existing = $label->getAttribute('class');
			$classes = array_filter(array_map('trim', explode(' ', $existing)));
			if (!in_array('fp-label', $classes)) $classes[] = 'fp-label';
			$label->setAttribute('class', implode(' ', $classes));
		}
	}

	// Wrap label + following controls into a .form-row div where possible
	$root = $doc->getElementById('root');
	if ($root) {
		// Collect labels first to avoid modifying live NodeList during iteration
		$labels = [];
		foreach ($xpath->query('.//label', $root) as $lbl) $labels[] = $lbl;

		foreach ($labels as $label) {
			$parent = $label->parentNode;
			// Create wrapper
			$wrapper = $doc->createElement('div');
			$wrapper->setAttribute('class', 'form-row');
			// Insert wrapper before label
			$parent->insertBefore($wrapper, $label);
			// Move label into wrapper
			$wrapper->appendChild($label);

			// Move subsequent sibling nodes into wrapper until next label or block separator
			$next = $wrapper->nextSibling; // after insertion, wrapper's nextSibling is what was after wrapper
			// But we need to use the node that comes after the label in the original parent.
			$node = $wrapper->nextSibling;
			// Instead, iterate starting from the node after the label (which is wrapper->nextSibling now)
			$curr = $wrapper->nextSibling;
			while ($curr && !($curr instanceof DOMElement && strtolower($curr->nodeName) === 'label')) {
				// Stop if we've reached an element that is another form-row wrapper we created
				if ($curr instanceof DOMElement && $curr->getAttribute('class') === 'form-row') break;
				$toMove = $curr;
				$curr = $toMove->nextSibling;
				$wrapper->appendChild($toMove);
			}
		}
	}

	// Add class to fieldsets
	foreach ($xpath->query('//*[@id="root"]//fieldset') as $fs) {
		if ($fs instanceof DOMElement) {
			$existing = $fs->getAttribute('class');
			$classes = array_filter(array_map('trim', explode(' ', $existing)));
			if (!in_array('choice-list', $classes)) $classes[] = 'choice-list';
			$fs->setAttribute('class', implode(' ', $classes));
		}
	}

	// Extract inner HTML of #root
	$root = $doc->getElementById('root');
	$htmlOut = '';
	if ($root) {
		foreach ($root->childNodes as $child) {
			$htmlOut .= $doc->saveHTML($child);
		}
	}
	libxml_clear_errors();
	return $htmlOut;
}

$form_code = sanitize_form_html($form_code);
$form_code = transform_form_html($form_code);


$username = $_SESSION['username'];
$warn = null;
if ($datasource === 'mysql') {
	include_once __DIR__ . '/includes/config.php';
	$mysql = get_mysql_pdo();
	if (!$mysql) {
		// MySQL not configured/available -> fallback to sqlite
		$warn = 'MySQL not available on server; saving to SQLite instead.';
		$datasource = 'sqlite';
	}
}

$stmt = $db->prepare("INSERT INTO forms (name, prompt, form_code, username, datasource) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$form_name, $prompt, $form_code, $username, $datasource]);
$form_id = $db->lastInsertId();

$updated_code = str_replace("TEMP_ID", $form_id, $form_code);
$stmt = $db->prepare("UPDATE forms SET form_code = ? WHERE id = ?");
$stmt->execute([$updated_code, $form_id]);
// If this was an AJAX request, return JSON so client can show prompt and redirect
$isAjax = false;
if ((!empty($_POST['ajax']) && $_POST['ajax'] == '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
	$isAjax = true;
}

if ($isAjax) {
	header('Content-Type: application/json');
	$response = ['success' => true, 'id' => $form_id];
	if (!empty($warn)) $response['warn'] = $warn;
	echo json_encode($response);
	exit;
} else {
	echo "<h2>Form Saved Successfully!</h2>";
	echo "<a href='forms.php'>View Saved Forms</a>";
}
?>
