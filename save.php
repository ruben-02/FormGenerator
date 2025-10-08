<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

$form_name = $_POST['form_name'];
$prompt = $_POST['prompt'];

// Original generated form HTML (already sanitized in generate.php)
$form_code = $_POST['form_code'];

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

$form_code = transform_form_html($form_code);


$username = $_SESSION['username'];
$stmt = $db->prepare("INSERT INTO forms (name, prompt, form_code, username) VALUES (?, ?, ?, ?)");
$stmt->execute([$form_name, $prompt, $form_code, $username]);
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
	echo json_encode(['success' => true, 'id' => $form_id]);
	exit;
} else {
	echo "<h2>Form Saved Successfully!</h2>";
	echo "<a href='forms.php'>View Saved Forms</a>";
}
?>
