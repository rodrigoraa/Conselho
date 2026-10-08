import { renderAsync } from 'docx-preview';

const container = document.querySelector('#document');
const toolbar = document.querySelector('#toolbar');
const output = document.querySelector('#zoom-value');
let scale = 1;
let fit = true;
let received = false;
let wrapper;
let pageWidth = 1;
const reply = state => window.parent.postMessage({ type: 'apc-docx-preview', state }, location.origin);
const setScale = value => {
  scale = Math.max(0.25, Math.min(2, value));
  wrapper.style.zoom = String(scale);
  output.textContent = `${Math.round(scale * 100)}%`;
};
const fitWidth = () => { if (wrapper && fit) setScale(Math.min(1, (container.clientWidth - 36) / pageWidth)); };
document.querySelector('#zoom-out').addEventListener('click', () => { fit = false; setScale(scale - 0.1); });
document.querySelector('#zoom-in').addEventListener('click', () => { fit = false; setScale(scale + 0.1); });
document.querySelector('#zoom-fit').addEventListener('click', () => { fit = true; fitWidth(); });
new ResizeObserver(fitWidth).observe(container);
// Document links never navigate the isolated viewer, including keyboard activation.
container.addEventListener('click', event => { if (event.target.closest('a')) event.preventDefault(); });
window.addEventListener('message', async event => {
  if (received || event.source !== window.parent || event.origin !== location.origin || event.data?.type !== 'apc-docx-render' || !(event.data.buffer instanceof ArrayBuffer)) return;
  received = true;
  try {
    await renderAsync(event.data.buffer, container, undefined, {
      renderAltChunks: false,
      useBase64URL: true,
      ignoreLastRenderedPageBreak: false,
      breakPages: true,
    });
    container.querySelectorAll('a').forEach(link => { link.removeAttribute('href'); link.removeAttribute('target'); });
    wrapper = container.querySelector('.docx-wrapper');
    const pages = [...container.querySelectorAll('section.docx')];
    if (!wrapper || !pages.length) throw new Error('Documento sem páginas.');
    pageWidth = Math.max(...pages.map(page => page.getBoundingClientRect().width));
    toolbar.hidden = false;
    fitWidth();
    reply('rendered');
  } catch {
    container.replaceChildren();
    reply('error');
  }
});
reply('ready');
