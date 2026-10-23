<div class="page-head"><div><h1><?= (int) $status ?> · <?= e(\Core\HttpException::reason($status)) ?></h1><p><?= e($status === 403 ? 'Your account does not have permission for this area. Ask a super admin if you need access.' : $message) ?></p></div></div>
<p><a class="btn" href="/admin">Back to the overview</a></p>
