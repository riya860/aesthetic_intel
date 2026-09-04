(() => {
  'use strict';
  const button=document.querySelector('[data-export-pdf]');
  if(!button)return;
  button.addEventListener('click',()=>{
    const original=button.textContent;
    button.disabled=true;button.textContent='Opening PDF view…';
    document.body.classList.add('pdf-exporting');
    setTimeout(()=>{
      window.print();
      setTimeout(()=>{document.body.classList.remove('pdf-exporting');button.disabled=false;button.textContent=original;},500);
    },350);
  });
})();
