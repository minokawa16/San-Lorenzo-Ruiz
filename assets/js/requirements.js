document.addEventListener('DOMContentLoaded', function(){
  const drop = document.getElementById('dropZone');
  const input = document.getElementById('fileInput');
  const previews = document.getElementById('previews');
  const namesContainer = document.getElementById('requirementNames');
  const notesField = document.getElementById('submissionNotes');
  ['dragenter','dragover','dragleave','drop'].forEach(ev=> drop.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); }));
  drop.addEventListener('drop', e=>{ let dt = e.dataTransfer; handleFiles(dt.files); });
  input.addEventListener('change', e=> handleFiles(e.target.files));

  function handleFiles(files){
    previews.innerHTML='';
    if (namesContainer) namesContainer.innerHTML='';
    for (let f of files){
      if (f.size > 5*1024*1024) { let p=document.createElement('div'); p.textContent = f.name + ' - too large'; previews.appendChild(p); continue; }
      let p = document.createElement('div'); p.className = 'req-preview';
      p.textContent = f.name + ' (' + Math.round(f.size/1024) + ' KB)';
      if (f.type.startsWith('image/')){ let img = document.createElement('img'); img.style.maxWidth='120px'; img.style.display='block'; img.src = URL.createObjectURL(f); p.appendChild(img); }
      // create corresponding requirement name input
      if (namesContainer){
        let wrap = document.createElement('div');
        wrap.className = 'req-name-row';
        let label = document.createElement('label'); label.textContent = 'Requirement name for ' + f.name;
        let inputName = document.createElement('input'); inputName.type='text'; inputName.name='requirement_names[]'; inputName.placeholder='e.g. Valid ID, Birth Certificate'; inputName.style.width='100%';
        wrap.appendChild(label); wrap.appendChild(inputName);
        namesContainer.appendChild(wrap);
      }
      previews.appendChild(p);
    }
  }
});
