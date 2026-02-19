# 🔌 CONECTAR VS CODE AO SERVIDOR - TUTORIAL COMPLETO

**Data:** 2026-02-09  
**Objetivo:** Editar arquivos do bot diretamente no servidor via VS Code

---

## 📋 MÉTODO 1: EXTENSÃO SFTP (RECOMENDADO) ✅

### **Passo 1: Instalar Extensão**

1. No VS Code, abra a aba de extensões (Ctrl + Shift + X)
2. Pesquise: **"SFTP"** por Natizyskunk
3. Clique em **Instalar**

**OU**

Extensões alternativas:
- **"FTP-Simple"** por Humy2833
- **"Remote - SSH"** (se tiver SSH)

---

### **Passo 2: Configurar SFTP**

#### **2.1. Criar arquivo de configuração**

No VS Code:
1. Pressione `Ctrl + Shift + P`
2. Digite: `SFTP: Config`
3. Selecione: `SFTP: Config`

Isso criará o arquivo `.vscode/sftp.json`

---

#### **2.2. Configuração para HOSTINGER**

**Se você tem SSH (planos Business/Cloud):**

```json
{
    "name": "Hostinger Bot Telegram",
    "host": "seudominio.com",
    "protocol": "sftp",
    "port": 22,
    "username": "u123456789",
    "password": "sua_senha_aqui",
    "remotePath": "/home/u123456789/public_html/meubot",
    "uploadOnSave": true,
    "useTempFile": false,
    "openSsh": false,
    "ignore": [
        "**/.vscode/**",
        "**/.git/**",
        "**/.DS_Store",
        "**/node_modules/**",
        "**/bot.log",
        "**/cron_delete.log",
        "**/*.md"
    ],
    "watcher": {
        "files": "**/*.{php,json,html}",
        "autoUpload": true,
        "autoDelete": false
    }
}
```

**Se você tem APENAS FTP (planos Single/Premium):**

```json
{
    "name": "Hostinger Bot Telegram",
    "host": "ftp.seudominio.com",
    "protocol": "ftp",
    "port": 21,
    "username": "usuario_ftp",
    "password": "senha_ftp",
    "remotePath": "/public_html/meubot",
    "uploadOnSave": true,
    "useTempFile": false,
    "ignore": [
        "**/.vscode/**",
        "**/.git/**",
        "**/.DS_Store",
        "**/bot.log",
        "**/cron_delete.log",
        "**/*.md"
    ],
    "watcher": {
        "files": "**/*.{php,json,html}",
        "autoUpload": true,
        "autoDelete": false
    }
}
```

---

### **Passo 3: Obter Credenciais da Hostinger**

#### **3.1. Acessar painel Hostinger**
1. Login em: https://hpanel.hostinger.com
2. Selecione seu site

#### **3.2. Credenciais FTP**
```
📍 Painel → Arquivos → Gerenciador de Arquivos → Contas FTP

📝 Anote:
- Host: ftp.seudominio.com (ou IP)
- Usuário: usuario@seudominio.com
- Senha: ••••••••
- Porta: 21 (FTP) ou 22 (SSH/SFTP)
- Caminho: /public_html/meubot
```

#### **3.3. Credenciais SSH (se disponível)**
```
📍 Painel → Avançado → SSH Access

📝 Anote:
- Host: ssh.seudominio.com
- Porta: 22
- Usuário: u123456789
- Senha: (mesma do painel)
```

---

### **Passo 4: Conectar e Sincronizar**

#### **4.1. Baixar arquivos do servidor**

No VS Code:
1. Pressione `Ctrl + Shift + P`
2. Digite: `SFTP: Download Project`
3. Aguarde o download completo

**Ou clique com botão direito na pasta e escolha:**
```
SFTP: Download Folder
```

---

#### **4.2. Testar conexão**

1. Abra um arquivo (ex: `bot.php`)
2. Faça uma pequena alteração (adicione um comentário)
3. Salve (`Ctrl + S`)
4. Se configurado com `uploadOnSave: true`, o arquivo sobe automaticamente!

**Verificar:**
```
Barra inferior do VS Code mostra:
✅ "Upload successful: bot.php"
```

---

### **Passo 5: Workflow de Trabalho**

#### **Editar arquivos:**
```
1. Abrir arquivo no VS Code
2. Fazer alterações
3. Salvar (Ctrl + S)
4. ✅ Arquivo sobe automaticamente para servidor!
```

#### **Comandos úteis (Ctrl + Shift + P):**
```
SFTP: Upload File              ← Enviar arquivo atual
SFTP: Upload Folder            ← Enviar pasta inteira
SFTP: Download File            ← Baixar arquivo do servidor
SFTP: Download Project         ← Baixar tudo
SFTP: Sync Local -> Remote     ← Sincronizar local para servidor
SFTP: Sync Remote -> Local     ← Sincronizar servidor para local
SFTP: Diff with Remote         ← Comparar diferenças
SFTP: List All                 ← Listar arquivos remotos
```

---

## 📋 MÉTODO 2: REMOTE - SSH (SE TIVER SSH) ✅✅

**Melhor opção se você tem SSH!**

### **Passo 1: Instalar Extensão**
```
1. Ctrl + Shift + X
2. Pesquisar: "Remote - SSH"
3. Instalar (Microsoft)
```

### **Passo 2: Configurar SSH**

#### **2.1. Criar configuração SSH**

No VS Code:
1. Pressione `Ctrl + Shift + P`
2. Digite: `Remote-SSH: Open SSH Configuration File`
3. Selecione: `C:\Users\meupa\.ssh\config`

Se não existir, crie:
```powershell
# No PowerShell
New-Item -Path "C:\Users\meupa\.ssh" -ItemType Directory -Force
New-Item -Path "C:\Users\meupa\.ssh\config" -ItemType File -Force
```

---

#### **2.2. Adicionar servidor no config**

Edite `C:\Users\meupa\.ssh\config`:

```ssh
Host hostinger-bot
    HostName ssh.seudominio.com
    User u123456789
    Port 22
    # IdentityFile ~/.ssh/id_rsa (se usar chave SSH)
```

---

### **Passo 3: Conectar**

1. Pressione `Ctrl + Shift + P`
2. Digite: `Remote-SSH: Connect to Host`
3. Selecione: `hostinger-bot`
4. Digite a senha quando solicitado
5. Aguarde conexão

**Pronto! Agora você está editando DIRETAMENTE no servidor!**

---

### **Passo 4: Abrir pasta do bot**

1. No VS Code conectado via SSH:
2. `File → Open Folder`
3. Digite: `/home/u123456789/public_html/meubot`
4. Clique em `OK`

**Agora todos os arquivos são do servidor!**

---

## 📋 MÉTODO 3: FTP-SIMPLE (ALTERNATIVA SIMPLES)

### **Passo 1: Instalar**
```
1. Ctrl + Shift + X
2. Pesquisar: "ftp-simple"
3. Instalar
```

### **Passo 2: Configurar**

1. Pressione `F1`
2. Digite: `ftp-simple: Config - FTP connection setting`

Arquivo será criado: `.vscode/ftp-simple.json`

```json
[
    {
        "name": "Hostinger",
        "host": "ftp.seudominio.com",
        "port": 21,
        "type": "ftp",
        "username": "usuario_ftp",
        "password": "senha_ftp",
        "path": "/public_html/meubot",
        "autosave": true,
        "confirm": false
    }
]
```

### **Passo 3: Conectar**

1. Pressione `F1`
2. Digite: `ftp-simple: Remote directory open to workspace`
3. Selecione: `Hostinger`

---

## ⚙️ CONFIGURAÇÃO RECOMENDADA (settings.json)

Adicione ao `settings.json` do VS Code:

```json
{
    // Auto-salvar arquivos
    "files.autoSave": "afterDelay",
    "files.autoSaveDelay": 1000,
    
    // Excluir arquivos desnecessários do explorador
    "files.exclude": {
        "**/.git": true,
        "**/.vscode": true,
        "**/node_modules": true,
        "**/*.log": true
    },
    
    // Formatação automática ao salvar
    "editor.formatOnSave": true,
    
    // PHP
    "[php]": {
        "editor.defaultFormatter": "bmewburn.vscode-intelephense-client"
    }
}
```

---

## 🔒 SEGURANÇA

### **⚠️ NUNCA faça isso:**

❌ **Comitar senhas no Git:**
```bash
# Adicione ao .gitignore
echo ".vscode/sftp.json" >> .gitignore
echo ".vscode/ftp-simple.json" >> .gitignore
```

---

### **✅ Use variáveis de ambiente:**

Crie arquivo `.env` (não comitar):
```env
FTP_HOST=ftp.seudominio.com
FTP_USER=usuario
FTP_PASS=senha
```

Referencie no `sftp.json`:
```json
{
    "host": "${env:FTP_HOST}",
    "username": "${env:FTP_USER}",
    "password": "${env:FTP_PASS}"
}
```

---

## 🧪 TESTE DE CONEXÃO

### **Teste 1: Upload manual**
```
1. Abrir bot.php
2. Adicionar comentário: // teste conexao
3. Salvar
4. Verificar no FileZilla se arquivo foi atualizado
```

### **Teste 2: Download**
```
1. Ctrl + Shift + P
2. SFTP: Download File
3. Selecionar arquivo
4. Verificar se baixou
```

---

## 🐛 TROUBLESHOOTING

### **Problema 1: "Connection refused"**
```
Causa: Porta ou protocolo errado
Solução: 
- FTP: porta 21
- SFTP/SSH: porta 22
- Verificar se protocolo está correto
```

### **Problema 2: "Permission denied"**
```
Causa: Credenciais incorretas
Solução:
- Verificar usuário e senha no painel Hostinger
- Testar conexão no FileZilla primeiro
```

### **Problema 3: "Timeout"**
```
Causa: Firewall ou host incorreto
Solução:
- Verificar firewall do Windows
- Testar host: ping ftp.seudominio.com
- Usar IP ao invés do domínio
```

### **Problema 4: Upload não automático**
```
Causa: uploadOnSave desabilitado
Solução:
- Verificar sftp.json: "uploadOnSave": true
- Ou fazer upload manual: Ctrl+Shift+P → Upload File
```

---

## 📊 COMPARAÇÃO DE MÉTODOS

| Método | SSH? | FTP? | Auto-Upload | Dificuldade | Recomendado |
|--------|------|------|-------------|-------------|-------------|
| **SFTP Ext** | ✅ | ✅ | ✅ | Fácil | ⭐⭐⭐⭐⭐ |
| **Remote SSH** | ✅ | ❌ | N/A | Média | ⭐⭐⭐⭐⭐ |
| **FTP-Simple** | ❌ | ✅ | ✅ | Muito Fácil | ⭐⭐⭐⭐ |
| **FileZilla** | ✅ | ✅ | ❌ | Fácil | ⭐⭐⭐ |

---

## ✅ CHECKLIST FINAL

- [ ] Extensão instalada
- [ ] Arquivo sftp.json configurado
- [ ] Credenciais corretas
- [ ] Teste de conexão funcionando
- [ ] Upload automático ativado
- [ ] .gitignore configurado
- [ ] Backup feito antes de testar

---

## 🎉 RESULTADO

Depois de configurado:

**Workflow:**
```
1. Abrir arquivo no VS Code
2. Editar código
3. Salvar (Ctrl + S)
4. ✅ Arquivo automaticamente enviado ao servidor!
5. Testar no bot Telegram
```

**Sem precisar:**
- ❌ Abrir FileZilla
- ❌ Fazer upload manual
- ❌ Trocar de programa

**Tudo dentro do VS Code! 🚀**

---

**Data:** 2026-02-09  
**Desenvolvedor:** Verdent AI  
**Status:** ✅ **TUTORIAL COMPLETO**

**🔌 VS Code + Servidor = Edição Direta!**
