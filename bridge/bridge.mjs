#!/usr/bin/env node
import readline from 'node:readline';

// Przekaźnik stdio dla Node 20 bez zależności. URL jest stały przez cały czas życia procesu.
const token = process.env.ASM_MCP_TOKEN;
let url;
try {
  url = new URL(process.env.ASM_MCP_URL);
  const entries = [...url.searchParams];
  const plain = entries.length === 1 && entries[0][0] === 'rest_route' && entries[0][1] === '/asm-mcp/v1/mcp' && (url.pathname.endsWith('/') || url.pathname.endsWith('/index.php'));
  const pretty = entries.length === 0 && url.pathname.endsWith('/wp-json/asm-mcp/v1/mcp');
  if (url.username || url.password || url.hash || !(plain || pretty)) throw new Error();
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['127.0.0.1','localhost','[::1]'].includes(url.hostname))) throw new Error();
  if (!/^[a-f0-9]{32}\.[a-f0-9]{64}$/.test(token ?? '')) throw new Error();
} catch {
  process.stderr.write('Sprawdź ASM_MCP_URL i ASM_MCP_TOKEN w środowisku procesu.\n');
  process.exit(1);
}
let version = '2025-11-25';
const input = readline.createInterface({ input:process.stdin, crlfDelay:Infinity });
async function relay(line) {
  let message;
  try { if (Buffer.byteLength(line)>262144) throw new Error(); message=JSON.parse(line); if (!message || Array.isArray(message)) throw new Error(); }
  catch { process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:null,error:{code:-32700,message:'Nieprawidłowe żądanie.'}})+'\n'); return; }
  const hasId = Object.hasOwn(message,'id');
  try {
    const response = await fetch(url,{method:'POST',redirect:'error',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json',Accept:'application/json, text/event-stream','MCP-Protocol-Version':version},body:JSON.stringify(message),signal:AbortSignal.timeout(60000)});
    if (response.status===202 && !hasId) return;
    if (!response.ok) throw new Error();
    const text=await response.text(); if (Buffer.byteLength(text)>2097152) throw new Error();
    const result=JSON.parse(text);
    if (result.jsonrpc!=='2.0' || result.id!==message.id) throw new Error();
    if (message.method==='initialize' && result.result?.protocolVersion) version=result.result.protocolVersion;
    if (hasId) process.stdout.write(JSON.stringify(result)+'\n');
  } catch {
    if (hasId) process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:message.id,error:{code:-32000,message:'Brak bezpiecznej odpowiedzi serwera. Sprawdź dostęp i wynik operacji.'}})+'\n');
  }
}
// Przetwarzaj wiersze szeregowo, aby zachować kolejność powiadomień initialized. Bez ponawiania mutacji.
let queue=Promise.resolve();
input.on('line',line=>{ queue=queue.then(()=>relay(line)); });
