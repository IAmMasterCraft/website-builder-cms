<?= view('install/_steps', ['step' => $step]) ?>
<h1>Start with demo content?</h1>
<p class="muted">Demo content is a fictional community association with news, events, albums, leaders and menus, so you can see how everything fits together. Edit or delete it later.</p>
<form class="panel panel-body fields" method="post" action="/install/content">
  <?= csrf_field() ?>
  <label class="check"><input type="radio" name="demo" value="1" checked> Yes, load demo content</label>
  <label class="check"><input type="radio" name="demo" value="0"> No, start with an empty site</label>
  <div class="form-actions"><button class="btn accent" type="submit">Finish installation</button></div>
  <p class="hint">Loading demo content takes a few seconds while images are generated.</p>
</form>
