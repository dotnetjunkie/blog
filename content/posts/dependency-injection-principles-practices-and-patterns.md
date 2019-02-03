---
title:	"Dependency Injection Principles, Practices, and Patterns"
date:	2019-01-29
author: Steven van Deursen
tags:   [.NET General, Architecture, C#, Dependency Injection]
draft:	false
---

### The book *Dependency Injection Principles, Practices, and Patterns* has gone to print.

<img style="float:right;margin-left:10px;border:1px;" src="/steven/images/book cover small.png" title="Cover of Dependency Injection Principles, Practices, and Patterns" alt="" />

For the last two years I've been coauthoring the book [Dependency Injection Principles, Practices, and Patterns](https://manning.com/seemann2). This is a revised and expanded edition of Manning's bestselling classic [Dependency Injection in .NET](https://manning.com/seemann) by [Mark Seemann](https://blog.ploeh.dk).

I always loved the first edition as it was a game changer for me. I learned a lot about DI and DI Containers and the book had a big influence on [Simple Injector](https://simpleinjector.org), even before it was published, as I was a vivid reader of early access version that was first released in [October 2009](http://blog.ploeh.dk/2009/10/05/Writingabook/).

For the second edition, Mark decided to join forces with me. This allowed us to combine our ideas and vision, which has led to some interesting directions and new insights. For instance, we change the status of the [Ambient Context](https://blogs.msdn.microsoft.com/ploeh/2007/07/23/ambient-context/) pattern [to an anti-pattern](https://blog.ploeh.dk/2019/01/21/some-thoughts-on-anti-patterns/).

But the general theme of this new edition is that it even more focusses on the principles, practices, and patterns that underpin Dependency Injection, which is the main reason for us changing the title of the book. There are several areas where you will notice this:

* The first three parts of the book are written in a container-agnostic way. This enforces our vision that DI is first and foremost a technique, and that DI Containers are useful, but optional tools.
* The SOLID principles are discussed much earlier in the book.
* There is a completely new chapter titled *Aspect Oriented Programming by Design*, which explains how you should prefer applying coming design principles and patterns over tooling (e.g. dynamic interception and compile-time weaving) when it comes to applying cross-cutting concerns. The second edition not does an even better job in explaining why compile-time weaving is not an attractive practice. In fact, in the second edition we state that it is the exact opposite of DI, and we consider it to be a *DI anti-pattern*.

I think that this book has a lot to bring, even to seasoned developers that are familiar with DI. The book is first and foremost about DI, but the common thread throughout the book is that writing well-designed code, as the two concepts are inseparable.

I would like to thank, Mark, Manning, everybody who provided feedback, and everyone who purchases the Early Access edition.

I hope you will enjoy my work.

## Comments
