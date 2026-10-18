# Bablito

Freelance contract listing site.

## Deploy
Push to `main` -> GitHub Actions (`.github/workflows/deploy.yml`) rsyncs the repo to `DEPLOY_PATH` over SSH and writes `.env` from repository secrets.
