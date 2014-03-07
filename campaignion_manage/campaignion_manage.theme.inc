<?php

function theme_campaignion_manage_node($variables) {
  $node = $variables['node'];
  $title = $node->title;
  $status_translated = ($node->status == 1) ? t('Published') : t('Draft');
  $status_css = 'status-' . (($node->status == 1) ? 'published' : 'draft');
  $type_translated = t($node->type);
  $content_type_name = node_type_get_name($node);
  $user = user_load($node->uid);
  $author = $user->name;

  if (($path = drupal_lookup_path('alias','node/' . $node->nid)) == FALSE) {
    $path = 'node/' . $node->nid;
  }
  $link = l($title, $path);
  $langcode = $node->language;
  $language_html = "";
  if ($langcode) {
    $languages = language_list();
    $langcode_uppercase = strtoupper($langcode);

    if (isset($languages[$langcode])) {
      $language = $languages[$langcode];
      $language_html = "<span class=\"language\">".$language->name." (".$langcode_uppercase.")</span>";
    } else {
      $language_html = "<span class=\"language\">" . t('No language') . " (".$langcode_uppercase.")</span>";
    }
  }

  $author_html = '';
  if (!empty($author)) {
    $author_html .= empty($language_html) ? "\n" : ",\n";
    $author_html .= '<span class="author">' . $author . '</span>';
  }

  $content = <<<EOT
<span class="icon"></span>
<div class="heading">
<span class="title">{$link}</span>
<span class="status {$status_css}">{$status_translated}</span>
</div>
<div class="additional-info">
<span class="type">{$content_type_name}</span>
{$language_html}{$author_html}
</div>
EOT;

  return $content;
}