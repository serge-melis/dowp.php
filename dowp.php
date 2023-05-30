<?php

define('DAYONE_JOURNAL', 'Default');
define('WORDPRESS_FILE', './wordpress.xml');
define('FILES_DIRECTORY', './wp-content/uploads/');

// This was created for a blog hosted on WordPress.com ...
define('EMBEDDED_LINKED_IMAGES_REGEX', '/<a href="http[s]{0,1}:\/\/WEBSITENAME[a]{0,1}l\.files\.wordpress\.com\/.*<\/a>/m');
// ... so the images were jetpacked.
define('EMBEDDED_IMAGES_REGEX', '/<img .*src="http[s]{0,1}:\/\/WEBSITENAME\.files\.wordpress\.com\/([\w_\.\/]*)[\?][\w=]*" .* \/>/m');

$attachments = [];
$posts = [];

function getAllData($sxe, &$attachments, &$posts) {

	$items = $sxe->xpath('//item');
	foreach ($items as $item) {
		switch ($item->wp_post_type) {
			case 'attachment':

				$data = array();
				foreach ($item->wp_postmeta as $wp_postmeta) {
					if ($wp_postmeta->wp_meta_key == '_wp_attachment_metadata') {
						$data = unserialize($wp_postmeta->wp_meta_value);
					}
				}
				$filename = substr($data['file'], strpos($data['file'], '/files')+7);
				$attachments[md5_file(FILES_DIRECTORY.$filename)] = array(
					'parent' => intval($item->wp_post_parent),
					'meta' => $data['image_meta'],
					'md5' => md5_file(FILES_DIRECTORY.$filename),
					'file' => $filename
				);
				break;

			case 'page':
			case 'post':
				$posts[] = $item;
				break;
			default:
				// page and nav_menu_item
				break;
		}
	}
}

function getTags($post) {

	$tags_for_post = [];
	foreach ($post->category as $category) {
		array_push($tags_for_post, xml_attribute($category, 'nicename'));
	}

	return $tags_for_post;
}

function getAttachments($attachments, $post) {

	$attachments_for_post = [];

	foreach ($attachments as $filename => $attachment) {
		if ($attachment['parent'] == $post->wp_post_id) {
			$attachments_for_post[$filename] = $attachment;
		}
	}

	// check the post itself, get all images
	$matches = null;
	preg_match_all(EMBEDDED_IMAGES_REGEX, $post->content_encoded.'', $matches, PREG_SET_ORDER, 0);

	foreach ($matches as $match) {

		$filename = $match[1];
		$md5 = md5_file(FILES_DIRECTORY.$filename);

		if (array_key_exists($md5, $attachments)) {
			if (array_key_exists($md5, $attachments_for_post)) {
				continue; // Already linked.
			} else {
				$attachments_for_post[$md5] = $attachments[$md5];
				//print_r("$filename was not linked before?\r\n");
			}
		} else {
			//print_r("$filename is not in the attachments array?\r\n");
		}
	}

	return $attachments_for_post;
}

function cleanText($text) {

	// Remove all captions...
	$re = '/\[caption.*\[\/caption\]/m';
	$text = preg_replace($re, '', $text);

	// Remove all links to files...
	$text = preg_replace(EMBEDDED_LINKED_IMAGES_REGEX, '', $text);

	// Fix space...
	$text = str_replace("&nbsp;", '', $text);

	// Fix lists...
	$text = str_replace("
-       ", '- ', $text);

	// Fix breaks...
	$text = str_replace("


", "
", $text);

	// Add breaks for images.
	$text = str_replace("/></a>", "
/></a>

", $text);

	// Fix strong...
	$text = str_replace("<strong", '<b', $text);
	$text = str_replace("</strong", '</b', $text);
	// Fix em...
	$text = str_replace("<em", '<i', $text);
	$text = str_replace("</em", '</i', $text);
	// Fix blockquote...
	$text = str_replace("<blockquote>", '', $text);
	$text = str_replace("</blockquote>", '', $text);
	// Fix empty paras...
	$text = str_replace(' style="text-align:center;"', '', $text);
	$text = str_replace(' style="padding-left:30px;"', '', $text);
	$text = str_replace(' style="text-align:left;"', '', $text);
	$text = str_replace(' style="text-decoration:underline;"', '', $text);
 	$text = str_replace(' style="padding-left:60px;"', '', $text);
	$text = str_replace("<p></p>", '', $text);
	$text = str_replace("<i></i>", '', $text);
	$text = str_replace("<span></span>", '', $text);

	return $text;
}

function xml_attribute($object, $attribute) {
	if (isset($object[$attribute])) {
		return (string) $object[$attribute];
	}
}

function loadAndFixFile() {
	// Load and prepare the file.
	// Reading namespaces is going to take me too much time,
	// so I just replace the colons...
	$sxe_file = file_get_contents(WORDPRESS_FILE);
	$sxe_file = str_replace('<wp:', '<wp_', $sxe_file);
	$sxe_file = str_replace('</wp:', '</wp_', $sxe_file);
	$sxe_file = str_replace('<content:', '<content_', $sxe_file);
	$sxe_file = str_replace('</content:', '</content_', $sxe_file);
	$sxe = new SimpleXMLElement($sxe_file);
	return $sxe;
}

function getCommand($post_attachments, $post_tags, $post_gmt_date, $post_text_file) {

	$attachmentsValue = '';
	if (count($post_attachments) > 0) {
		$attachmentsValue = '-a';
		foreach ($post_attachments as $path => $data) {
			$attachmentsValue .= ' ' . FILES_DIRECTORY . $data['file'];
		}
	}

	$tagsValue = (count($post_tags) > 0)
		? sprintf('-t %s --', join(' ', $post_tags))
		: ''
	;

	$coordinatesValue = '';
	if (count($post_attachments) > 0) {
		foreach ($post_attachments as $attachment) {
			if (array_key_exists('meta', $attachment)) {
				if (array_key_exists('latitude', $attachment['meta'])) {
					$coordinatesValue = sprintf(
						'--coordinate %s %s',
						$attachment['meta']['latitude'],
						$attachment['meta']['longitude']
					);
				}
			}
		}
	}

	$command = sprintf(
		"cat ./files/$post_text_file | dayone2 -j %s  -z GMT -d='%s' %s %s %s new",
		DAYONE_JOURNAL,
		$post_gmt_date,
		$attachmentsValue,
		$coordinatesValue,
		$tagsValue,
	);

	return $command;
}

function main($dryrun) {

	// Load and fix the file:
	$sxe = loadAndFixFile();

	// Get all the data...
	getAllData($sxe, $attachments, $posts);

	foreach ($posts as $post) {

		$post_attachments = getAttachments($attachments, $post);
		$post_tags = getTags($post);
		$post_gmt_date = $post->wp_post_date_gmt.'';
		$post_text_file = sprintf('%s.html', $post->wp_post_name);
		file_put_contents('files/'.$post_text_file,
			cleanText($post->title.'') .
			"\r\n\r\n" .
			cleanText($post->content_encoded.'')
		);

		$command = getCommand($post_attachments, $post_tags, $post_gmt_date, $post_text_file);
		print "Sending: $post->title\r\n";
		if ($dryrun) {
			print "Executing: $command\r\n";
		} else {
			system($command);			
		}
	}
}

main(true);
