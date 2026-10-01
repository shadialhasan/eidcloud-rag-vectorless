# EidCloud Architecture & Security Guide
tags: auth, security, architecture, api

## Overview
EidCloud provides a decentralized cloud infrastructure designed for microservices and AI agent deployment.

## Authentication and Authorization
EidCloud implements zero-trust JWT authentication combined with Ed25519 cryptographic signatures.
Every API request must include a bearer token signed by the regional authentication service.
Tokens have an expiration time of 900 seconds to minimize exposure risks.
Role-based access control (RBAC) maps granular permissions to team roles.

## Database & Data Persistence
The storage subsystem employs distributed SQLite and LSM tree replication for low latency.
Replication across availability zones is managed via Raft consensus.
Backups are snapshots encrypted at rest with AES-256-GCM.

## Deployment Best Practices
Deployments should always run behind Envoy reverse proxies with TLS 1.3 termination.
Container images are verified with Sigstore Cosign before scheduling onto worker nodes.
