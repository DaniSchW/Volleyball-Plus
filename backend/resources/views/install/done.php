<?php
/**
 * @var bool $env_written
 * @var string $env
 * @var string $cron_command
 * @var string $cron_url
 * @var string $tenant_url
 */
$t = fn (string $key, array $replace = []) => e(__("install.done.{$key}", $replace));

ob_start();
?>
<h1><?= $t('heading') ?></h1>

<section>
  <?php if ($env_written) { ?>
    <p class="ok"><?= $t('env_written') ?></p>
  <?php } else { ?>
    <p class="warn"><?= $t('env_manual') ?></p>
    <p class="bad"><?= $t('env_secret') ?></p>
    <pre><?= e($env) ?></pre>
  <?php } ?>
</section>

<section>
  <h2><?= $t('cron_heading') ?></h2>
  <p><?= $t('cron_intro') ?></p>
  <p><?= $t('cron_url') ?></p>
  <code class="block"><?= e($cron_url) ?></code>
  <p style="margin-top:12px"><?= $t('cron_command') ?></p>
  <code class="block"><?= e($cron_command) ?></code>
</section>

<a class="button" href="<?= e($tenant_url) ?>"><?= $t('open_app') ?></a>
<?php
$content = ob_get_clean();
$title = __('install.done.title');
include __DIR__.'/layout.php';
