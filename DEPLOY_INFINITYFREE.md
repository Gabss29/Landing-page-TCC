# Deploy no InfinityFree (com SendGrid)

Este documento descreve passo-a-passo como publicar sua aplicação PHP + MySQL no InfinityFree e ativar o envio de newsletters via SendGrid (API HTTP).

IMPORTANTE: InfinityFree frequentemente bloqueia SMTP/mail() — por isso usamos a API do SendGrid (HTTP) que é confiável mesmo em hospedagens grátis.

1) Criar conta e site no InfinityFree
   - Crie uma conta em https://infinityfree.net e adicione um novo site (domínio/subdomínio gratuito).

2) Criar banco de dados MySQL
   - No painel InfinityFree, vá em "MySQL Databases" e crie uma nova database + usuário + senha.
   - Anote: DB_HOST, DB_NAME, DB_USER, DB_PASS.

3) Fazer upload dos arquivos
   - Use FTP (ex.: FileZilla) com as credenciais fornecidas pelo painel (FTP hostname, username, password).
   - Faça upload de todos os arquivos do projeto para a pasta `htdocs` do seu site.

4) Composer e dependências
   - Recomendado: execute `composer require phpmailer/phpmailer` localmente no seu computador (não diretamente no InfinityFree se eles não oferecem composer).
   - Depois suba a pasta `vendor/` resultante para o servidor (upload via FTP). Isso ativa o fallback PHPMailer caso precise.

5) Configurar SendGrid
   - Crie uma conta SendGrid (https://sendgrid.com) e gere uma API Key (full access para envio).
   - No seu site (por FTP), edite `sendgrid_config.php` e coloque sua API Key, `from_email` e `from_name`.
   - Exemplo:
     ```php
     return [
       'api_key' => 'SG.xxxxx',
       'from_email' => 'noreply@seudominio.com',
       'from_name' => 'SomaZoi'
     ];
     ```

6) Configurar `conexao.php`
   - Abra `conexao.php` e ajuste as credenciais do PDO para apontarem para o DB criado no InfinityFree.

7) Testar inscrição / unsubscribe
   - Acesse sua home page e inscreva um e-mail. Verifique na tabela `newsletter_emails` que `unsubscribe_token` foi gerado.
   - Se o SendGrid estiver configurado, verifique se o e-mail de boas-vindas chegou. Caso contrário, verifique `newsletter_logs`.
   - Teste o link `unsubscribe.php?t=TOKEN` para confirmar remoção (active=0).

8) Envio em massa / agendado
   - InfinityFree não oferece cron nativo — use um serviço externo como cron-job.org (gratuito) ou EasyCron.
   - Agende um job que faça requisição HTTP (GET or POST) para um endpoint protegido no seu site, ex.:
     `https://seusite.com/send_newsletter.php` (requer login de base, então você pode criar um endpoint que aceite uma chave secreta `?key=SECRET` para execução remota).

9) Segurança e boas práticas
   - Nunca suba sua API Key em repositórios públicos.
   - Preferir variáveis de ambiente para produção, mas em hospedagem gratuita isso nem sempre é possível — pelo menos não comitar keys.
   - Proteger `send_newsletter.php` com autenticação mais forte (somente admins reais poderão entrar). O código atual restringe a `usuario_tipo === 'cuidador'`.

10) Problemas comuns
   - Se e-mails não chegarem, confira: SendGrid API Key correta, SPF/DKIM do domínio (opcional para entrega melhor), checagem de logs em SendGrid dashboard.

Se quiser, eu posso:
 - gerar um script de deploy (zip) pronto para upload; ou
 - se você me autorizar, eu faço o upload por FTP direto (você precisa me fornecer as credenciais FTP + DB via um canal seguro), ou
 - orientar você passo-a-passo com comandos e checks.
