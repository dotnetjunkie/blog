---
title:	"Dependency Injection Principles, Practices, and Patterns"
date:	2019-03-08
author: Steven van Deursen
tags:   [.NET General, Architecture, C#, Dependency Injection]
draft:	false
---

### The book *Dependency Injection Principles, Practices, and Patterns* has gone to print.

<img style="float:right;margin-left:20px;border:1px;" src="/steven/images/book cover small.png" title="Cover of Dependency Injection Principles, Practices, and Patterns" alt="" />

For the last two years I've been coauthoring the book [Dependency Injection Principles, Practices, and Patterns](https://manning.com/seemann2). This is a revised and expanded edition of [Manning](https://manning.com)'s bestselling classic [Dependency Injection in .NET](https://manning.com/seemann) by [Mark Seemann](https://blog.ploeh.dk).

I always loved the first edition as it was a game changer for me. I learned a lot about DI, DI Containers, and software design. The book even had a big influence on [Simple Injector](https://simpleinjector.org)'s philosophy. This influence on Simple Injector even started well before the book was published, as I was a vivid reader of early access version that was first released in [October 2009](http://blog.ploeh.dk/2009/10/05/Writingabook/).

For the second edition, Mark decided to join forces with me. This allowed us to combine our ideas and visions, which has led to some interesting directions and new insights. For instance, we changed the status of the [Ambient Context](https://blogs.msdn.microsoft.com/ploeh/2007/07/23/ambient-context/) pattern [to an anti-pattern](https://blog.ploeh.dk/2019/01/21/some-thoughts-on-anti-patterns/) and explain in much detail why this is an anti-pattern, and we provide better alternatives.

The general theme of this second edition is the focus on the principles, practices, and patterns that underpin Dependency Injection, which is the main reason for the change in the book's title. There are several areas where you will notice this:

* The first three parts of the book are written in a container-agnostic way. This strengthens our vision that DI is first and foremost a technique, and that DI Containers are useful, but optional tools.
* The [SOLID](https://en.wikipedia.org/wiki/SOLID) principles are discussed much earlier in the book and we refer to them more often while discussing when and why we take shortcuts.
* There is a completely new chapter titled *Aspect Oriented Programming by Design* (chapter 10), which explains that, when it comes to applying [cross-cutting concerns](https://en.wikipedia.org/wiki/Cross-cutting_concern), you should prefer using well-known design principles and patterns over tooling (such as dynamic interception and compile-time weaving). We consider this chapter the climax of the book—this is where many readers using the early access program said they began to see the contours of a tremendously powerful way to model software.

<img style="float:right;margin-left:20px;border:1px;" src="/steven/images/pile-of-books.png" title="Pile of Dependency Injection Principles, Practices, and Patterns books" alt="" />

Apart from these changes related to the focus of the book, there are many other changes as well, for instance:

* All code examples are now given using .NET Core, although admittedly, for the most part, the code examples will work in any .NET version and are still very understandable to non-.NET developers as well. This allows many developers of other OOP languages to get something out of the book, which is something both Mark and I find very important.
* The second edition now discusses a different set of DI Containers. We decided to remove all containers that were discussed in the first edition, except Autofac, as this seems to be the most popular DI library at the time of writing. As the book discusses .NET Core, we decided to include a chapter on Microsoft's new DI Container (MS.DI) as well, while explaining how limiting that library is, especially when applied to all the principles and patterns described in the book. Besides Autofac and MS.DI, the book also contains a chapter on Simple Injector, which is, as you might know, the DI Container I maintain.

I think that this book has a lot to bring, even to seasoned developers that are familiar with DI, and even for developers working in different OOP languages. The book is first and foremost about DI, but the common thread throughout the book is that of writing well-designed code, as the two concepts are inseparable.

I'm extremely glad I was able to be part of this process and was able to make an awesome book even better.

I would like to thank, Mark, Manning, everybody who provided feedback, and everyone who purchases the Early Access edition.

I hope you will enjoy my work.

## Comments

---
#### Steven - 03 April 19

The book forum on Manning's website contained a more detailed description of the changes we made in the second edition but, unfortunatelly, Manning pulled the plug on the forum. With it, all posts including that description turned to dust.

Below is a (slightly altered) copy of that description:

##### *What the motivation is for the second edition, and, what's new?*

Our main motivation for writing a the second edition is to share our new knowledge with a broad audience. Although blog posts, presentations, and Pluralsight videos allow us to get this message across, there is no medium as suited to get a complicated story across as a book. 

Because writing a new book is a major undertaking, Mark has asked me to help him. It was simply too much to chew off for Mark alone.

##### What will not change:

* The second edition will still be solely about implementing DI in statically typed object-oriented languages. Examples are still just in C#. Functional Programming has its own patterns and practices and deserves a book of its own. 
* Each chapter will still start with a cooking analogy. 

##### What will change: 

The second edition focusses even more on patterns & practices than the first edition already did. There are several areas you will notice this:

* The discussion of **DI Containers** is completely moved to Part 4. The first 3 parts of the book are completely container agnostic. 
* More examples are added and many parts are completely re-written throughout the book. 
* The **Ambient Context** pattern is now considered an anti-pattern and we describe in detail why that is (see [section 5.3](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-5/section-5-3)).
* The original *DI refactorings* chapter (6) is almost completely rewritten—it now describes code smells (see [chapter 6](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-6)).
* We added new sidebars and new sections where we warn about bad practices and bad design decisions and describe our own personal experiences.
* We start referring to the **SOLID** principles much earlier in the book (in the first edition they were first mentioned in chapter 9). 
* We removed some complexity and ambiguity in the book's running code samples. This allows the reader to focus more on applying the patterns and practices. 
* The *Interception* chapter (9) is rewritten for the most part. We added this much information that we decided to split up the chapter in three distinct chapters (9, 10, and 11. In [chapter 9](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-9)), we focus on the Decorator pattern as method of interception. 
* [Chapter 10](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-10) contains complete new material discussing how to apply **Aspect-Oriented Programming** (AOP) based on the SOLID design principles. In many ways, we consider chapter 10 to be the climax of the book.
* [Chapter 11](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-11) focusses on applying AOP using **Dynamic Interception** and **Compile-Time Weaving** tooling. This information was available in chapter 9 of the first edition, but we elaborated the discussion to explain all the downsides that these approaches have compared to the methods described in chapter 10.
* We now consider compile-time weaving a DI anti-pattern and [section 11.2](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-11/section-11-2) describes in detail why compile-time weaving is a bad practice when it comes to applying **Volatile Dependencies**.
* [Chapter 12](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-12/) describes the basics of DI Containers and goes into details how to choose between **Pure DI** and a DI Container. This chapter is an updated version of chapter 3 of the first edition.
* This edition discusses three DI Containers: Autofac, Simple Injector, and Microsoft.Extensions.DependencyInjection. Each container gets its own chapter, and although these chapters are based on the first edition, they also describe how to use those containers in combination with new concepts described in this edition, such as the "AOP by design" approach, laid out in chapter 10, and domain events from chapter 6.
* [Chapter 15](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-15/) describes the *Microsoft.Extensions.DependencyInjection* container, and discusses in much detail what the limitations and downsides of this simplistic DI Container implementations are, while we do show how to work around some of its limitations. (spoiler alert: it won't be pretty)
* With the help of Manning’s readability experts, the second edition did an even better job to get the message across. 

The book focusses on .NET Core and its frameworks. Although there is still a lot of code that works for any .NET version, especially the parts that show how to integrate (most notably [chapter 7](https://livebook.manning.com/#!/book/dependency-injection-principles-practices-patterns/chapter-7/)), are focused on .NET Core and ASP.NET Core.

We incorporated many of the lessons we learned and knowledge we gained since the first edition was published. This will sometimes manifest itself in small notes or warnings, up to sidebars or even complete sections or chapters.

