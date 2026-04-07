const CDN_WHITELIST = [
  "cdnjs.cloudflare.com",
  "cdn.jsdelivr.net",
  "unpkg.com",
  "esm.sh",
];

const DANGEROUS_TAGS = /<(iframe|object|embed|meta|link|base|form)[\s>][\s\S]*?<\/\1>/gi;
const DANGEROUS_VOID = /<(iframe|object|embed|meta|link|base)\b[^>]*\/?>/gi;

export function sanitizeForStreaming(html: string): string {
  return html
    .replace(DANGEROUS_TAGS, "")
    .replace(DANGEROUS_VOID, "")
    .replace(/\s+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>"']*)/gi, "")
    .replace(/<script[\s\S]*?<\/script>/gi, "")
    .replace(/<script\b[^>]*\/?>/gi, "")
    .replace(
      /\s+(href|src|action)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>"']*))/gi,
      (match, _attr: string, dq?: string, sq?: string, uq?: string) => {
        const url = (dq ?? sq ?? uq ?? "").trim();
        if (/^\s*(javascript|data)\s*:/i.test(url)) {
          return "";
        }
        return match;
      },
    );
}

export function sanitizeForIframe(html: string): string {
  return html
    .replace(DANGEROUS_TAGS, "")
    .replace(DANGEROUS_VOID, "");
}

export function buildReceiverSrcdoc(): string {
  const rootStyle = typeof document !== "undefined" && typeof getComputedStyle === "function"
    ? getComputedStyle(document.documentElement)
    : null;
  const cspDomains = CDN_WHITELIST.map((d) => `https://${d}`).join(" ");
  const csp = [
    "default-src 'none'",
    `script-src 'unsafe-inline' ${cspDomains}`,
    "style-src 'unsafe-inline'",
    "img-src * data: blob:",
    "font-src * data:",
    "connect-src 'none'",
  ].join("; ");

  const styleBlock = `
    :root {
      --color-background-primary: ${rootStyle?.getPropertyValue("--panel-strong").trim() || "#fffaf1"};
      --color-background-secondary: ${rootStyle?.getPropertyValue("--panel").trim() || "#fffdf8"};
      --color-background-tertiary: ${rootStyle?.getPropertyValue("--bg").trim() || "#f5efe4"};
      --color-text-primary: ${rootStyle?.getPropertyValue("--text").trim() || "#20150f"};
      --color-text-secondary: ${rootStyle?.getPropertyValue("--muted").trim() || "#6e6257"};
      --color-text-tertiary: ${rootStyle?.getPropertyValue("--muted").trim() || "#6e6257"};
      --color-border-primary: ${rootStyle?.getPropertyValue("--line").trim() || "rgba(61, 46, 35, 0.18)"};
      --color-border-secondary: ${rootStyle?.getPropertyValue("--line").trim() || "rgba(61, 46, 35, 0.12)"};
      --color-border-tertiary: ${rootStyle?.getPropertyValue("--line").trim() || "rgba(61, 46, 35, 0.12)"};
      --border-radius-md: 8px;
      --border-radius-lg: 12px;
      --font-sans: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --font-mono: ui-monospace, SFMono-Regular, Menlo, monospace;
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      margin: 0;
      padding: 0;
      background: transparent;
      color: var(--color-text-primary);
      font-family: var(--font-sans);
    }

    body {
      overflow: hidden;
    }

    a {
      color: inherit;
    }
  `;

  const receiverScript = `(function(){
var root=document.getElementById('__root');
var _t=null,_first=true;
function _h(){
if(_t)clearTimeout(_t);
_t=setTimeout(function(){
var h=document.body.scrollHeight;
if(h>0)parent.postMessage({type:'widget:resize',height:h,first:_first},'*');
_first=false;
},60);
}
var _ro=new ResizeObserver(_h);
_ro.observe(document.body);
function applyHtml(html){root.innerHTML=html;_h();}
function finalizeHtml(html){
var tmp=document.createElement('div');
tmp.innerHTML=html;
var ss=tmp.querySelectorAll('script');
var scripts=[];
for(var i=0;i<ss.length;i++){
scripts.push({src:ss[i].src||'',text:ss[i].textContent||'',attrs:[]});
for(var j=0;j<ss[i].attributes.length;j++){
var a=ss[i].attributes[j];
if(a.name!=='src')scripts[scripts.length-1].attrs.push({name:a.name,value:a.value});
}
ss[i].remove();
}
var visualHtml=tmp.innerHTML;
if(root.innerHTML!==visualHtml)root.innerHTML=visualHtml;
for(var i=0;i<scripts.length;i++){
var n=document.createElement('script');
if(scripts[i].src)n.src=scripts[i].src;
else if(scripts[i].text)n.textContent=scripts[i].text;
for(var j=0;j<scripts[i].attrs.length;j++)n.setAttribute(scripts[i].attrs[j].name,scripts[i].attrs[j].value);
root.appendChild(n);
}
_h();
}
window.addEventListener('message',function(e){
if(!e.data)return;
switch(e.data.type){
case 'widget:update': applyHtml(e.data.html); break;
case 'widget:finalize': finalizeHtml(e.data.html); setTimeout(_h,150); break;
}
});
document.addEventListener('click',function(e){
var a=e.target&&e.target.closest?e.target.closest('a[href]'):null;
if(!a)return;
var h=a.getAttribute('href');
if(!h||h.charAt(0)==='#')return;
e.preventDefault();
parent.postMessage({type:'widget:link',href:h},'*');
});
window.__widgetSendMessage=function(t){
if(typeof t!=='string'||t.length>500)return;
parent.postMessage({type:'widget:sendMessage',text:t},'*');
};
parent.postMessage({type:'widget:ready'},'*');
})();`;

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="${csp}">
<style>${styleBlock}</style>
</head>
<body>
<div id="__root"></div>
<script>${receiverScript}</script>
</body>
</html>`;
}
