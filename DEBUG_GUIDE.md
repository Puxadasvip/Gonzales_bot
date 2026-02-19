# 🔍 GUIA DE DEBUG DOS BOTÕES

## 📋 Passo a Passo para Identificar o Problema

### 1. Limpar o log
```powershell
Clear-Content C:\xampp\htdocs\meuprojeto\meubot\bot.log
```

### 2. Enviar /menu no Telegram
- Abra o bot no Telegram
- Envie `/menu`
- Aguarde o menu aparecer

### 3. Clicar em "💎 Meu Plano VIP"
- Clique no botão
- Aguarde alguns segundos

### 4. Verificar o log
```powershell
Get-Content C:\xampp\htdocs\meuprojeto\meubot\bot.log
```

## 🎯 O que procurar no log:

### ✅ Se aparecer isso = BOT FUNCIONANDO:
```
🔔 CALLBACK RECEBIDO: {"callback_id":"...","data":"VIP_MEUPLANO|..."}
✅ Entrando em VIP_MEUPLANO
📤 Tentando responder callback
✅ Callback respondido com sucesso!
```

### ❌ Se NÃO aparecer nada = WEBHOOK NÃO ESTÁ FUNCIONANDO
Possíveis causas:
1. Webhook não configurado
2. Apache/PHP não está rodando
3. Firewall bloqueando

### ⚠️ Se aparecer erro de API = PROBLEMA COM TELEGRAM
```
❌ Erro ao responder callback: ...
```

## 🔧 Soluções por Problema:

### Problema 1: Log vazio (webhook não funciona)
```powershell
# Verificar se o Apache está rodando
Get-Service | Where-Object {$_.Name -like "*apache*"}

# Verificar webhook configurado
curl https://api.telegram.org/bot<SEU_TOKEN>/getWebhookInfo
```

### Problema 2: Callback expirado
Adicionar no início do bot.php (linha 2000):
```php
// Responde IMEDIATAMENTE, antes de qualquer processamento
if (isset($update['callback_query']['id'])) {
    answerCallback($update['callback_query']['id'], '', false);
}
```

### Problema 3: Erro de permissão
```powershell
# Dar permissão ao diretório
icacls C:\xampp\htdocs\meuprojeto\meubot /grant Everyone:F /T
```

## 📝 Envie para mim:

Após testar, me envie:
1. O conteúdo do `bot.log`
2. Print do erro no Telegram (se houver)
3. Resultado do comando: `curl https://api.telegram.org/bot<TOKEN>/getWebhookInfo`

---

## 🚀 Teste Rápido (Local):
```powershell
cd C:\xampp\htdocs\meuprojeto\meubot
C:\xampp\php\php.exe test_callback.php
```

Se o teste local funcionar, o problema é no webhook!
