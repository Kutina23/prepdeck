(function(){
  /* past questions library: data comes from the database (window.LIB) */
  var LOCK='<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
  var DOC='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg>';
  var LIB=window.LIB||[],st={d:0,s:"all",q:""},rows=document.getElementById("rows"),input=document.getElementById("q");
  function esc(t){return String(t).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];});}
  function render(){
    if(!rows)return;
    var list=[];
    if(st.q){var q=st.q.toLowerCase();LIB.forEach(function(d){d.p.forEach(function(p){if((p.code+" "+p.course+" "+p.title).toLowerCase().indexOf(q)>-1)list.push(p);});});}
    else if(LIB[st.d]){list=LIB[st.d].p.filter(function(p){return st.s==="all"||p.ls===st.s;});}
    if(!list.length){
      rows.innerHTML='<div class="row" style="--i:0;grid-template-columns:1fr"><div><b>No papers here yet</b><small></small></div></div>';
      rows.querySelector("small").textContent=st.q?('Nothing matches "'+st.q+'". Check the course code or try a word from the title.'):"Nothing uploaded for this level and semester yet.";
      return;
    }
    rows.innerHTML=list.slice(0,30).map(function(p,i){
      var lv=p.ls.split("-");
      return '<div class="row" style="--i:'+i+'"><span class="code">'+esc(p.code)+'</span>'+
        '<div><b>'+esc(p.course)+'</b><small>'+esc(p.title)+' &middot; Level '+lv[0]+' &middot; Semester '+lv[1]+' &middot; '+esc(p.year)+'</small></div>'+
        '<div class="go"><a class="tag" href="'+esc(p.href)+'">'+DOC+'View PDF</a>'+
        '<a class="tag lock" href="'+esc(window.PLANURL||"#pricing")+'" title="Worked answers come with PrepDeck Plus">'+LOCK+'Worked answers</a></div></div>';
    }).join("");
  }
  document.querySelectorAll(".dept").forEach(function(b){b.addEventListener("click",function(){st.d=+b.dataset.d;st.q="";if(input)input.value="";document.querySelectorAll(".dept").forEach(function(x){x.setAttribute("aria-selected",x===b?"true":"false");});render();});});
  document.querySelectorAll(".sem").forEach(function(b){b.addEventListener("click",function(){st.s=b.dataset.s;st.q="";if(input)input.value="";document.querySelectorAll(".sem").forEach(function(x){x.setAttribute("aria-pressed",x===b?"true":"false");});render();});});
  function search(v){
    if(!document.getElementById("library")){var s=document.getElementById("schools");if(s)s.scrollIntoView({behavior:"smooth",block:"start"});var p=document.getElementById("spick");if(p)p.focus();return;}
    st.q=v.trim();render();document.getElementById("library").scrollIntoView({behavior:"smooth",block:"start"});}
  var find=document.getElementById("find");if(find)find.addEventListener("submit",function(e){e.preventDefault();search(input.value);});
  document.querySelectorAll(".chip").forEach(function(c){c.addEventListener("click",function(){input.value=c.dataset.q;search(c.dataset.q);});});
  render();

  /* choose your school: a searchable dropdown built over the plain <select> (which still works without JavaScript) */
  var spick=document.getElementById("spick");
  if(spick){
    var go=function(id){location.href=PDBASE+"?school="+id+"#library";};
    var all=[].slice.call(spick.querySelectorAll("option[value]")).filter(function(o){return o.value;}).map(function(o){return {id:o.value,name:o.textContent,region:o.parentNode.label||""};});
    var cur=spick.selectedOptions[0]&&spick.value?spick.selectedOptions[0].textContent:"";
    var box=document.createElement("div");box.className="sdd";
    box.innerHTML='<button type="button" class="sdd-btn" aria-haspopup="listbox" aria-expanded="false"><span></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>'+
      '<div class="sdd-panel" hidden><input type="search" class="sdd-q" placeholder="Search school, short name or region" aria-label="Search schools" autocomplete="off"><div class="sdd-list" role="listbox" aria-label="Schools"></div></div>';
    spick.hidden=true;var sub=spick.form.querySelector("button[type=submit]");if(sub)sub.hidden=true;
    spick.parentNode.insertBefore(box,spick);
    var btn=box.querySelector(".sdd-btn"),panel=box.querySelector(".sdd-panel"),q=box.querySelector(".sdd-q"),list=box.querySelector(".sdd-list"),shown=[],act=-1;
    btn.querySelector("span").textContent=cur||"Choose your school";
    function draw(){
      var t=q.value.trim().toLowerCase(),h="",g=null;act=-1;
      shown=all.filter(function(s){return !t||(s.name+" "+s.region).toLowerCase().indexOf(t)>-1;});
      shown.forEach(function(s,i){if(s.region!==g){g=s.region;h+='<div class="sdd-grp">'+esc(g||"Other")+'</div>';}h+='<button type="button" role="option" data-i="'+i+'">'+esc(s.name)+'</button>';});
      list.innerHTML=h||'<div class="sdd-none">No school matches. <a href="'+PDBASE+'register.php?missing=1">Ask us to add yours</a>.</div>';
    }
    function mark(i){var b=list.querySelectorAll("button[data-i]");if(!b.length)return;act=(i+b.length)%b.length;b.forEach(function(x,k){x.setAttribute("aria-selected",k===act?"true":"false");});b[act].scrollIntoView({block:"nearest"});}
    function open(){panel.hidden=false;btn.setAttribute("aria-expanded","true");q.value="";draw();q.focus();}
    function close(){panel.hidden=true;btn.setAttribute("aria-expanded","false");}
    btn.addEventListener("click",function(){panel.hidden?open():close();});
    q.addEventListener("input",draw);
    q.addEventListener("keydown",function(e){
      if(e.key==="ArrowDown"){e.preventDefault();mark(act+1);}else if(e.key==="ArrowUp"){e.preventDefault();mark(act-1);}
      else if(e.key==="Enter"){e.preventDefault();var s=shown[act>=0?act:0];if(s)go(s.id);}else if(e.key==="Escape"){close();btn.focus();}
    });
    list.addEventListener("click",function(e){var b=e.target.closest("button[data-i]");if(b)go(shown[+b.dataset.i].id);});
    document.addEventListener("click",function(e){if(!box.contains(e.target))close();});
    spick.focus=function(){btn.focus();open();};   // the hero "Find papers" search opens it
  }

  /* phone menu for the top navigation */
  var nb=document.getElementById("navbtn"),hdr=nb?nb.closest(".nav"):null;
  if(nb&&hdr){
    var setNav=function(on){hdr.classList.toggle("open",on);nb.setAttribute("aria-expanded",on?"true":"false");nb.setAttribute("aria-label",on?"Close menu":"Open menu");};
    nb.addEventListener("click",function(){setNav(!hdr.classList.contains("open"));});
    hdr.querySelectorAll("nav a").forEach(function(a){a.addEventListener("click",function(){setNav(false);});});
    document.addEventListener("click",function(e){if(!hdr.contains(e.target))setNav(false);});
    document.addEventListener("keydown",function(e){if(e.key==="Escape")setNav(false);});
  }

  /* flashcard */
  var flip=document.getElementById("flip");
  flip.addEventListener("click",function(){flip.setAttribute("aria-pressed",flip.getAttribute("aria-pressed")==="true"?"false":"true");});

  /* match game */
  var tiles=[].slice.call(document.querySelectorAll("#match .m"));
  var hint=document.getElementById("mhint");
  var first=null;
  tiles.forEach(function(t){
    t.addEventListener("click",function(){
      if(t===first){t.setAttribute("aria-pressed","false");first=null;return;}
      if(!first){first=t;t.setAttribute("aria-pressed","true");return;}
      if(first.dataset.k===t.dataset.k){
        first.classList.add("done");t.classList.add("done");
        first.setAttribute("aria-pressed","false");first=null;
        if(tiles.every(function(x){return x.classList.contains("done");})){
          hint.textContent="All matched. Harder rounds are in Plus.";
        }else{hint.textContent="Match.";}
      }else{
        var a=first;first=null;a.setAttribute("aria-pressed","false");
        [a,t].forEach(function(x){x.classList.add("bad");setTimeout(function(){x.classList.remove("bad");},420);});
        hint.textContent="Not a pair. Try again.";
      }
    });
  });

  /* footer alerts form: saves the email so admins can notify about new papers */
  var af=document.getElementById("alerts"),am=document.getElementById("alertMsg"),ai=document.getElementById("alertEmail");
  if(af)af.addEventListener("submit",function(e){
    e.preventDefault();
    if(!ai.value||!ai.checkValidity()){am.textContent="Enter a valid email address, like you@school.edu.";ai.focus();return;}
    api("site.php?a=subscribe",{email:ai.value}).then(function(){am.textContent="Thanks. We will email you when new papers arrive.";ai.value="";}).catch(function(err){am.textContent=err.message;});
  });

  /* billing toggle: prices come from the database (window.PRICES) */
  document.querySelectorAll(".toggle button").forEach(function(b){
    b.addEventListener("click",function(){
      document.querySelectorAll(".toggle button").forEach(function(x){x.setAttribute("aria-pressed",x===b?"true":"false");});
      var p=(window.PRICES||{})[b.dataset.b];if(!p)return;
      document.getElementById("amt").textContent=p.a;document.getElementById("per").textContent=p.p;document.getElementById("bnote").textContent=p.n;
    });
  });

  /* scroll reveals: content stays visible unless it starts below the fold */
  var items=[].slice.call(document.querySelectorAll(".reveal"));
  var calm=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;
  if(!calm&&"IntersectionObserver" in window){
    var io=new IntersectionObserver(function(es){
      es.forEach(function(e){
        if(e.isIntersecting){e.target.classList.remove("pre");io.unobserve(e.target);}
      });
    },{threshold:.12,rootMargin:"0px 0px -6% 0px"});
    items.forEach(function(el){
      if(el.getBoundingClientRect().top>window.innerHeight){el.classList.add("pre");io.observe(el);}
    });
  }
})();
