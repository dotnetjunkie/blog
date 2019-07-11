---
title:			"Dependency Injection Principles, Practices, and Patterns"
date:			2019-03-08
author: 		Steven van Deursen
tags:   		[.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId:	3
draft:			false
---

### The book *Dependency Injection Principles, Practices, and Patterns* has gone to print.

<img style="float:right;margin-left:2.5%;border:1px;max-width:50%;" src="/steven/images/book cover small.png" title="Cover of Dependency Injection Principles, Practices, and Patterns" alt="" />

For the last two years I've been coauthoring the book [Dependency Injection Principles, Practices, and Patterns](https://manning.com/seemann2). This is a revised and expanded edition of [Manning](https://manning.com)'s bestselling classic [Dependency Injection in .NET](https://manning.com/seemann) by [Mark Seemann](https://blog.ploeh.dk).

I always loved the first edition as it was a game changer for me. I learned a lot about DI, DI Containers, and software design. The book even had a big influence on [Simple Injector](https://simpleinjector.org)'s philosophy. This influence on Simple Injector even started well before the book was published, as I was a vivid reader of early access version that was first released in [October 2009](http://blog.ploeh.dk/2009/10/05/Writingabook/).

For the second edition, Mark decided to join forces with me. This allowed us to combine our ideas and visions, which has led to some interesting directions and new insights. For instance, we changed the status of the [Ambient Context](https://blogs.msdn.microsoft.com/ploeh/2007/07/23/ambient-context/) pattern [to an anti-pattern](https://blog.ploeh.dk/2019/01/21/some-thoughts-on-anti-patterns/) and explain in much detail why this is an anti-pattern, and we provide better alternatives.

The general theme of this second edition is the focus on the principles, practices, and patterns that underpin Dependency Injection, which is the main reason for the change in the book's title. There are several areas where you will notice this:

* The first three parts of the book are written in a container-agnostic way. This strengthens our vision that DI is first and foremost a technique, and that DI Containers are useful, but optional tools.
* The [SOLID](https://en.wikipedia.org/wiki/SOLID) principles are discussed much earlier in the book and we refer to them more often while discussing when and why we take shortcuts.
* There is a completely new chapter titled *Aspect Oriented Programming by Design* (chapter 10), which explains that, when it comes to applying [cross-cutting concerns](https://en.wikipedia.org/wiki/Cross-cutting_concern), you should prefer using well-known design principles and patterns over tooling (such as dynamic interception and compile-time weaving). We consider this chapter the climax of the book—this is where many readers using the early access program said they began to see the contours of a tremendously powerful way to model software.

<img style="float:right;margin-left:2.5%;border:1px;max-width:50%;" src="/steven/images/pile-of-books.png" title="Pile of Dependency Injection Principles, Practices, and Patterns books" alt="" />

Apart from these changes related to the focus of the book, there are many other changes as well, for instance:

* All code examples are now given using .NET Core, although admittedly, for the most part, the code examples will work in any .NET version and are still very understandable to non-.NET developers as well. This allows many developers of other OOP languages to get something out of the book, which is something both Mark and I find very important.
* The second edition now discusses a different set of DI Containers. We decided to remove all containers that were discussed in the first edition, except Autofac, as this seems to be the most popular DI library at the time of writing. As the book discusses .NET Core, we decided to include a chapter on Microsoft's new DI Container (MS.DI) as well, while explaining how limiting that library is, especially when applied to all the principles and patterns described in the book. Besides Autofac and MS.DI, the book also contains a chapter on Simple Injector, which is, as you might know, the DI Container I maintain.

I think that this book has a lot to bring, even to seasoned developers that are familiar with DI, and even for developers working in different OOP languages. The book is first and foremost about DI, but the common thread throughout the book is that of writing well-designed code, as the two concepts are inseparable.

I'm extremely glad I was able to be part of this process and was able to make an awesome book even better.

I would like to thank, Mark, Manning, everybody who provided feedback, and everyone who purchases the Early Access edition.

I hope you will enjoy my work.

## Comments
