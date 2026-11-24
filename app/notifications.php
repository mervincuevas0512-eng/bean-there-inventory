<?php
declare(strict_types=1);

/** Render one shared, accessible popup for one or more server messages. */
function notification_html(string $type, array $messages): string {
  $type = $type === 'success' ? 'success' : 'error';
  $messages = array_values(array_filter(array_map('strval', $messages), static fn(string $message) => trim($message) !== ''));
  if (!$messages) return '';
  $title = $type === 'success' ? 'Success!' : 'Something went wrong';
  $icon = $type === 'success' ? '✓' : '!';
  $content = count($messages) === 1
    ? '<p class="notice-message">'.e($messages[0]).'</p>'
    : '<ul class="notice-message-list">'.implode('', array_map(
      static fn(string $message): string => '<li>'.e($message).'</li>', $messages
    )).'</ul>';
  return '<div class="notice-overlay" data-notice-popup data-notice-type="'.$type.'" hidden>'.
    '<section class="notice-dialog notice-'.$type.'" tabindex="-1" role="alertdialog" aria-modal="true" aria-labelledby="notice-title" aria-describedby="notice-message">'.
    '<span class="notice-symbol" aria-hidden="true">'.$icon.'</span>'.
    '<h2 id="notice-title">'.$title.'</h2>'.
    '<div class="notice-content" id="notice-message">'.$content.'</div>'.
    '<button class="btn btn-primary notice-dismiss" type="button" data-notice-dismiss>OK, Got It</button>'.
    '</section></div>';
}
