<template id="media-picker-template">
  <dialog class="picker-dialog" aria-labelledby="picker-title">
    <div class="picker-head"><h2 id="picker-title">Choose an image</h2><label for="picker-q" class="sr-only">Search images</label><input type="search" id="picker-q" placeholder="Search…"><button type="button" class="btn small ghost" data-close>Close</button></div>
    <ul class="picker-grid"></ul>
    <p class="picker-status" role="status"></p>
  </dialog>
</template>
