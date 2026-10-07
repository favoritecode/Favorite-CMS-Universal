'use strict';
const fs=require('fs'),path=require('path'),assert=require('assert');
const {JSDOM,VirtualConsole}=require(process.env.CMS_TEST_NODE_MODULES ? path.join(process.env.CMS_TEST_NODE_MODULES,'jsdom') : './browser/node_modules/jsdom');
const root=path.resolve(__dirname,'..');const renders=process.env.CMS_TEST_RENDER_DIR;
if(!renders)throw new Error('Set CMS_TEST_RENDER_DIR to the generated rendered screen fixtures.');
const results=[];const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function test(name,fn){try{await fn();results.push({test:name,status:'PASS'});}catch(error){results.push({test:name,status:'FAIL',error:error.message});}}
async function screen(name,fetchMock,blockedStorage=false){
 const errors=[],virtualConsole=new VirtualConsole();virtualConsole.on('jsdomError',e=>{if(!/Not implemented: (navigation|HTMLFormElement)/.test(e.message))errors.push(e.message);});
 const dom=new JSDOM(fs.readFileSync(path.join(renders,name+'.html'),'utf8'),{url:'http://localhost/admin/customize',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole});
 const w=dom.window;if(blockedStorage)Object.defineProperty(w,'localStorage',{get(){throw new w.DOMException('Storage blocked','SecurityError');}});w.fetch=fetchMock || (()=>Promise.resolve({ok:true,status:200,json:()=>Promise.resolve({success:true})}));w.confirm=()=>true;
 const previews=[];w.HTMLFormElement.prototype.submit=function(){previews.push({action:this.action,fields:Array.from(new w.FormData(this).entries())});};
 w.document.querySelectorAll('script').forEach(s=>{if(!s.src && s.textContent.trim() && !s.type.includes('json'))w.eval(s.textContent);});
 w.document.querySelectorAll('[onclick],[oninput]').forEach(element=>{ for(const type of ['click','input']) { const code=element.getAttribute('on'+type); if(code)element.addEventListener(type,event=>new w.Function('event',code).call(element,event)); } });
 await wait(10);return {dom,w,errors,previews,document:w.document};
}
(async()=>{
 const ui=await screen('light-admin-Customize');const w=ui.w,d=ui.document,form=d.getElementById('core-fallback-form');
 const field=form.elements.namedItem('mods[footer_copyright]'),original=field.value,undo=d.getElementById('core-btn-undo'),redo=d.getElementById('core-btn-redo');
 await test('generic customizer initializes one baseline without dirty state',()=>{assert.equal(undo.disabled,true);assert.equal(d.getElementById('core-status-text').textContent,'All changes saved');assert.equal(w.FavoriteBuilder.history.undoStack.length,1);});
 await test('customizer edits activate history and dirty feedback',async()=>{field.value='Unsaved footer';field.dispatchEvent(new w.Event('input',{bubbles:true}));assert.match(d.getElementById('core-status-text').textContent,/Unsaved/);await wait(350);assert.equal(undo.disabled,false);});
 await test('Undo restores saved values; Redo restores edits',()=>{undo.click();assert.equal(field.value,original);assert.equal(d.getElementById('core-status-text').textContent,'All changes saved');assert.equal(redo.disabled,false);redo.click();assert.equal(field.value,'Unsaved footer');});
 const checkboxes=Array.from(form.querySelectorAll('input[name^="sections["]'));
 await test('reorder remains unsaved and Undo restores both row and checkbox states',async()=>{
  undo.click();const initial=checkboxes.map(c=>c.name);checkboxes[0].checked=!checkboxes[0].checked;checkboxes[0].dispatchEvent(new w.Event('change',{bubbles:true}));await wait(350);
  const row=checkboxes[1].parentElement.parentElement;row.querySelector('button[name=section_id]').click();
  const moved=Array.from(form.querySelectorAll('input[name^="sections["]')).map(c=>c.name);assert.notDeepEqual(moved,initial);
  undo.click();assert.deepEqual(Array.from(form.querySelectorAll('input[name^="sections["]')).map(c=>c.name),initial);assert.equal(checkboxes[0].checked,false);
  undo.click();assert.equal(checkboxes[0].checked,true);assert.equal(ui.errors.length,0,ui.errors.join('\n'));
 });
 await test('color picker edits keep paired text input and history in sync',async()=>{const picker=form.querySelector('input[type=color]');picker.value='#ec4899';picker.dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(form.elements.namedItem('mods[accent_color]').value,'#ec4899');await wait(350);undo.click();assert.notEqual(picker.value,'#ec4899');assert.notEqual(form.elements.namedItem('mods[accent_color]').value,'#ec4899');});
 await test('unsaved preview submits all current controls to read-only endpoint',async()=>{field.value='Preview footer';field.dispatchEvent(new w.Event('input',{bubbles:true}));await wait(1000);assert(ui.previews.length>0);const preview=ui.previews.at(-1);assert.match(preview.action,/\/admin\/customize\/preview$/);assert(preview.fields.some(([k,v])=>k==='mods[footer_copyright]' && v==='Preview footer'));assert(preview.fields.some(([k])=>k==='_token'));assert.equal(field.value,'Preview footer');});
 await test('successful AJAX Save establishes baseline without losing form state',async()=>{form.requestSubmit();await wait(20);assert.equal(d.getElementById('core-status-text').textContent,'All changes saved');assert.equal(field.value,'Preview footer');});
 await test('unsaved navigation is guarded and saved navigation remains free',()=>{const saved=new w.Event('beforeunload',{cancelable:true});w.dispatchEvent(saved);assert.equal(saved.defaultPrevented,false);field.value='Dirty again';field.dispatchEvent(new w.Event('input',{bubbles:true}));const dirty=new w.Event('beforeunload',{cancelable:true});w.dispatchEvent(dirty);assert.equal(dirty.defaultPrevented,true);});
 ui.dom.window.close();
 const failed=await screen('dark-admin-Customize',()=>Promise.resolve({ok:false,status:500,json:()=>Promise.resolve({success:false,error:'Fixture save failure'})}));
 await test('failed save keeps values, dirty state and retry controls',async()=>{const f=failed.document.getElementById('core-fallback-form'),c=f.elements.namedItem('mods[footer_copyright]');c.value='Keep me';c.dispatchEvent(new failed.w.Event('input',{bubbles:true}));f.requestSubmit();await wait(20);assert.equal(c.value,'Keep me');assert.match(failed.document.getElementById('core-status-text').textContent,/Fixture save failure/);assert.equal(f.hasAttribute('aria-busy'),false);});failed.dom.window.close();
 const busy=await screen('light-admin-Customize',()=>new Promise(resolve=>{busy.resolve=resolve;}));
 await test('editing during Save remains dirty after the earlier snapshot succeeds',async()=>{const f=busy.document.getElementById('core-fallback-form'),c=f.elements.namedItem('mods[footer_copyright]');c.value='Sent version';c.dispatchEvent(new busy.w.Event('input',{bubbles:true}));f.requestSubmit();c.value='Newer unsaved version';c.dispatchEvent(new busy.w.Event('input',{bubbles:true}));busy.resolve({ok:true,status:200,json:()=>Promise.resolve({success:true})});await wait(20);assert.equal(c.value,'Newer unsaved version');assert.equal(busy.document.getElementById('core-status-text').textContent,'Unsaved changes');});busy.dom.window.close();
 const editor=await screen('light-edit-Post',()=>Promise.resolve({ok:true,status:200,json:()=>Promise.resolve({success:true,drafts:[],history:[]})}),true);
 await test('post editor initializes in blocked storage and keeps all commands',()=>{assert(editor.document.getElementById('post-editor-form').favoriteEditor);assert(editor.document.querySelectorAll('[data-cmd]').length>10);assert(!editor.errors.some(e=>e.includes('SecurityError')));});
 await test('server restore adapter opens raw snapshot in code mode without running it',()=>{const f=editor.document.getElementById('post-editor-form');f.favoriteEditor.set('<script>window.qaExecuted=true</script><p>Restored</p>');assert.equal(editor.w.qaExecuted,undefined);assert(f.favoriteEditor.read().includes('Restored'));assert.equal(editor.document.getElementById('code-mode-container').style.display,'block');});
 await test('media gallery cards support keyboard selection and dialog semantics',()=>{const card=editor.document.querySelector('.media-picker-card'),modal=editor.document.getElementById('media-modal');assert.equal(card.tabIndex,0);assert.equal(card.getAttribute('role'),'button');assert.equal(modal.getAttribute('role'),'dialog');assert.equal(modal.getAttribute('aria-modal'),'true');let clicked=0;card.addEventListener('click',()=>clicked++);card.dispatchEvent(new editor.w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));assert.equal(clicked,1);});
 await test('media dialog traps Tab, closes on Escape and returns focus to opener',async()=>{
  const d=editor.document,w=editor.w,modal=d.getElementById('media-modal'),opener=d.getElementById('set-feat-img-btn');
  modal.querySelectorAll('button,input,select,textarea,a,[tabindex]').forEach(element=>{element.getClientRects=()=>{for(let parent=element;parent;parent=parent.parentElement){if(w.getComputedStyle(parent).display==='none')return [];}return [{width:10,height:10}];};});
  opener.focus();opener.click();await wait(10);const first=d.activeElement;assert(modal.contains(first));
  first.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Tab',shiftKey:true,bubbles:true,cancelable:true}));assert.notEqual(d.activeElement,first);assert(modal.contains(d.activeElement));
  d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));assert.equal(d.activeElement,first);
  d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));await wait(10);assert.equal(modal.style.display,'none');assert.equal(d.activeElement,opener);
 });
 editor.dom.window.close();
 const page=await screen('light-edit-Page',()=>Promise.resolve({ok:true,status:200,json:()=>Promise.resolve({success:true,drafts:[],history:[]})}));
 await test('page server restore keeps canonical code content and normal save action',()=>{const f=page.document.getElementById('page-editor-form');f.favoriteEditor.set('<p>Restored page</p>');assert.equal(f.favoriteEditor.read(),'<p>Restored page</p>');assert.equal(page.document.getElementById('page-content').value,'<p>Restored page</p>');assert.match(f.action,/\/admin\/pages\/update$/);});page.dom.window.close();

 const seoEditor=await screen('light-edit-Post',()=>Promise.resolve({ok:true,status:200,json:()=>Promise.resolve({success:true,drafts:[],history:[]})}));
 await test('automatic SEO preview reads content without writing metadata fields',async()=>{const f=seoEditor.document.getElementById('post-editor-form');const title=f.elements.namedItem('meta_title'),desc=f.elements.namedItem('meta_description');title.value='';desc.value='';f.elements.namedItem('excerpt').value='';f.favoriteEditor.set('<p>Useful preview content.</p><script>window.seoExecuted=true</script>');f.dispatchEvent(new seoEditor.w.Event('input',{bubbles:true}));await wait(250);assert.match(seoEditor.document.getElementById('seo-preview-description').textContent,/Useful preview content/);assert(!seoEditor.document.getElementById('seo-preview-description').textContent.includes('seoExecuted'));assert.equal(title.value,'');assert.equal(desc.value,'');assert.equal(seoEditor.w.seoExecuted,undefined);});
 await test('custom SEO preview retains typed metadata and updates live',async()=>{const f=seoEditor.document.getElementById('post-editor-form');f.elements.namedItem('meta_title').value='Chosen search title';f.elements.namedItem('meta_description').value='Chosen description';f.dispatchEvent(new seoEditor.w.Event('input',{bubbles:true}));await wait(250);assert.equal(seoEditor.document.getElementById('seo-preview-title').textContent,'Chosen search title');assert.equal(seoEditor.document.getElementById('seo-preview-description').textContent,'Chosen description');assert.equal(seoEditor.errors.length,0);});seoEditor.dom.window.close();

 for(const result of results)console.log(JSON.stringify(result));
 if(process.env.CMS_TEST_REPORT)fs.writeFileSync(process.env.CMS_TEST_REPORT,JSON.stringify(results,null,2));
 const failures=results.filter(r=>r.status==='FAIL').length;console.log(`${results.length} DOM behavior checks; ${failures} failures.`);process.exitCode=failures?1:0;
})().catch(error=>{console.error(error);process.exitCode=1;});