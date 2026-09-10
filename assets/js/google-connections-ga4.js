(function(){
 'use strict';
 function drawTrend(){
  var canvas=document.getElementById('google-ga4-trend-chart');
  var dataNode=document.getElementById('google-ga4-trend-data');
  if(!canvas||!dataNode)return;
  var rows=[];
  try{rows=JSON.parse(dataNode.textContent||'[]');}catch(e){return;}
  if(!Array.isArray(rows)||!rows.length)return;
  var ctx=canvas.getContext('2d');if(!ctx)return;
  function render(){
   var rect=canvas.getBoundingClientRect(),ratio=window.devicePixelRatio||1;
   var width=Math.max(300,Math.floor(rect.width)),height=Math.max(180,Math.floor(rect.height));
   canvas.width=Math.floor(width*ratio);canvas.height=Math.floor(height*ratio);ctx.setTransform(ratio,0,0,ratio,0,0);ctx.clearRect(0,0,width,height);
   var p={top:22,right:12,bottom:30,left:42},cw=width-p.left-p.right,ch=height-p.top-p.bottom;
   var max=Math.max.apply(null,rows.map(function(r){return Math.max(Number(r.sessions||0),Number(r.activeUsers||0));}).concat([1]));
   ctx.lineWidth=1;ctx.strokeStyle='rgba(95,96,105,.14)';ctx.fillStyle='#898a91';ctx.font='10px system-ui,sans-serif';
   for(var i=0;i<=3;i++){var y=p.top+ch*i/3;ctx.beginPath();ctx.moveTo(p.left,y);ctx.lineTo(width-p.right,y);ctx.stroke();ctx.fillText(Math.round(max*(1-i/3)).toLocaleString(),2,y+3);}
   function plot(key,color,lw){ctx.beginPath();ctx.strokeStyle=color;ctx.lineWidth=lw;rows.forEach(function(r,idx){var x=p.left+(rows.length===1?cw/2:cw*idx/(rows.length-1));var v=Number(r[key]||0);var y=p.top+ch-(v/max*ch);if(idx===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);});ctx.stroke();}
   plot('sessions','#18181c',2.2);plot('activeUsers','#92939a',1.7);
   var labels=Math.min(5,rows.length);for(var j=0;j<labels;j++){var ri=Math.round(j*(rows.length-1)/Math.max(1,labels-1)),r=rows[ri];var x=p.left+(rows.length===1?cw/2:cw*ri/(rows.length-1));ctx.fillStyle='#898a91';ctx.fillText(String(r.date||'').slice(5),Math.max(p.left,x-16),height-9);}
   ctx.fillStyle='#18181c';ctx.fillRect(p.left,4,14,2);ctx.fillText('Sessions',p.left+20,9);ctx.fillStyle='#92939a';ctx.fillRect(p.left+86,4,14,2);ctx.fillText('Active users',p.left+106,9);
  }
  render();var timer=null;window.addEventListener('resize',function(){clearTimeout(timer);timer=setTimeout(render,120);});
 }
 function eventSearch(){
  var input=document.querySelector('[data-google-event-search]'),list=document.querySelector('[data-google-event-list]');
  if(!input||!list)return;
  input.addEventListener('input',function(){var q=String(input.value||'').trim().toLowerCase();list.querySelectorAll('[data-google-event-row]').forEach(function(row){var name=String(row.getAttribute('data-event-name')||'');row.hidden=q!==''&&name.indexOf(q)===-1;});});
 }
 document.addEventListener('DOMContentLoaded',function(){drawTrend();eventSearch();});
})();
