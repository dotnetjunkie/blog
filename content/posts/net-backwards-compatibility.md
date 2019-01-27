---
title:	".NET Backwards compatibility, why should we?"
date:	2006-05-23
author: Steven van Deursen
tags:   [.NET General]
draft:	false
---

### Microsofts corporate vice present of the Developer Division, Somasegar, wrote on his weblog about the backwards compatibility of the .NET framework version 2.0. But his readers doubt the usefulness of this compatibility, as do I.

#### UPDATE 2010: This post was written in 2006. During the last couple of years I worked for many clients and found that the backwards compatibility of .NET was very important for many of my clients, because it allowed them to migrate slowly from one version to the next without making very big investments. My opinion on this subject therefore has changed. I currently support Microsoft in their quest to keep .NET backwards compatible. Please keep this in mind while reading this article.

In his [blog](http://blogs.msdn.com/somasegar/default.aspx) about the [compatibility of the coming Orcas release](http://blogs.msdn.com/somasegar/archive/2006/05/18/601354.aspx) of the .NET Framework, Somasegar writes Microsoft is doing everything to ensure backwards compatibility. This means, compiled DLL's of version X should run (without recompiling) on version X+1. Some of his readers have commented on this and note the .NET framework is developed in a way that versions can live side-by-side. This, according to the commenters, means there is actually no need for backwards compatibility.

I totally agree with the commenters. The .NET framework 2.0 has already got 90 Types and 1145 type members that are marked obsolete, but are left in for the sake of compatibility. Good example are the types in the System.Web.Mail namespace. They moved to the much more logical System.Net.Mail namespace. Microsoft should break the backwards compatibility with every new version of the framework to ensure that the new version has the best possible design. Like commenter John Galt writes: "Drop all of the crap that isn't needed.". I say learn from your errors, fix them and communicate that to the developers.

Of course this comes at a cost for developers. Forgetting about backwards compatibility allows more drastic changes to the framework. This could create more adaptation problems for developers, or increase time getting used to the new framework. (I say ‘framework’, because every new version then will be a new framework.) Besides, it is a great plus that existing ASP.NET 1.1 web applications can be ported to 2.0 with a simple mouseclick and immediately run much faster and are much more scalable than before.

These costs however are a small price to pay for what we get in return, because like FullMetal comments: "The product is new but you are already suffocating under the burden of legacy." So Microsoft, please please please reconsider your strategy for .NET version 3.0.

## Comments

