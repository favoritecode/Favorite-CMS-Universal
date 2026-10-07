const fs=require('fs'),path=require('path');const {test,expect}=require('@playwright/test');
const renderDirectory=process.env.CMS_TEST_RENDER_DIR || path.resolve(__dirname,'../renders');
if(!fs.existsSync(renderDirectory))throw new Error('Generate the PHP screen fixtures before running visual tests.');
const screens=fs.readdirSync(renderDirectory).filter(file=>file.endsWith('.html')).sort();
for(const file of screens)test(file,async({page,context},testInfo)=>{
 const theme=testInfo.project.name.split('-')[0];test.skip(!file.startsWith(theme+'-'));
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const baseURL=testInfo.project.use.baseURL || process.env.CMS_BROWSER_BASE_URL || 'http://127.0.0.1:8770';
 await context.addCookies([{name:'favorite_admin_theme',value:theme,url:baseURL}]);
 await page.addInitScript(mode=>localStorage.setItem('favorite_admin_theme',mode),theme);
 await page.goto('/screens/'+file,{waitUntil:'networkidle'});
 await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
 expect(errors).toEqual([]);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2)).toBe(true);
 // Editor actions must remain above the right panel in both appearance modes.
 if (/-(?:edit|new)-(?:Post|Page)\.html$/.test(file)) {
  const sidebar=page.locator('.editor-sidebar-sticky');
  const actions=page.locator('.editor-page-actions');
  await expect(actions).toBeVisible();
  const headerBox=await page.locator('.page-header').boundingBox();
  const sidebarBox=await sidebar.boundingBox();
  expect(sidebarBox.y).toBeGreaterThanOrEqual(headerBox.y+headerBox.height-1);
  const viewport=page.viewportSize();
  const expectedPosition=viewport.width>900 && viewport.height>480?'sticky':'static';
  expect(await sidebar.evaluate(element=>getComputedStyle(element).position)).toBe(expectedPosition);
  for(const action of await actions.locator('a,button').all()) {
   await expect(action).toBeVisible();
   const box=await action.boundingBox();
   expect(box.x).toBeGreaterThanOrEqual(0);
   expect(box.x+box.width).toBeLessThanOrEqual(viewport.width+1);
  }
 }
 await expect(page).toHaveScreenshot(file.replace('.html','.png'),{fullPage:true,mask:[page.locator('#workspace-status'),page.locator('[data-qa-dynamic]')]});
});
test('generic customizer undo redo and section order work in both modes',async({page},testInfo)=>{
 const theme=testInfo.project.name.split('-')[0];await page.goto('/screens/'+theme+'-admin-Customize.html');
 const input=page.locator('[name="mods[footer_copyright]"]'),undo=page.locator('#core-btn-undo'),redo=page.locator('#core-btn-redo');
 const before=await input.inputValue();await input.fill('Browser regression footer');await expect(page.locator('#core-status-text')).toHaveText('Unsaved changes');await expect(undo).toBeEnabled();await undo.click();await expect(input).toHaveValue(before);await redo.click();await expect(input).toHaveValue('Browser regression footer');
});