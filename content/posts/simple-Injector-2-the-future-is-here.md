---
title:   "Simple Injector 2 – The future is here"
date:    2013-03-04
author:  Steven van Deursen
tags:    [.NET general, Architecture, Dependency Injection, Simple Injector]
draft:   false
---

### Announcing the new major release of Simple Injector. The Simple Injector is an easy-to-use Inversion of Control library for .NET and Silverlight.

Last week [Simple Injector 2](https://simpleinjector.org/) was released. This release was a major undertaking. I've been working on this release full time for the last few months and I got a lot of help from enthusiastic Simple Injector users and even got a new developer on the team. I think the results are awesome. I believe it is safe to say that Simple Injector can now compete with the 5 big established DI libraries for .NET and I'm really proud of that.

Simple Injector follows the rules from [semantic versioning](https://semver.org/) and the fact that this is a new major release, implies that there are breaking changes. Simple Injector users should absolutely read [the release notes](https://simpleinjector.codeplex.com/releases/view/99008) before upgrading, but I think that in most cases the upgrade will go smoothly.

This release however is also a significant functional improvement over version 1.6. Most of the development time went to the new [Debug Diagnostic Services](https://simpleinjector.org/diagnostics). Those services allow you to get feedback on the container configuration. It allows spotting common misconfiguration mistakes such as implementations that depend on services with a shorter lifestyle. Castle Windsor has a limited version of this feature for some time now and IMHO, for a DI container to be usable in any considerably sized application, it must enable these kinds of analysis. I advise any new and existing Simple Injector user to take a good look at the [Debug Diagnostic Services documentation](https://simpleinjector.org/diagnostics) and see how to view the diagnostic results.

![Example of the Simple Injector Diagnostics Debugger Watch](/steven/images/diagnosticsdebuggerwatch.gif)

The last few years I reviewed a lot of DI configurations. I saw the same configuration mistakes. Over and over again. What I started to realize was that it is really easy -even for an experienced developer- to make these kinds of configuration mistakes, once the application starts to grow. When the application is complex enough, there is simply no alternative for wiring all dependencies in a single place of the application (the [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/)), but this places big responsibility on this piece of code and its developers when they make changes to it.

I have thought often and hard about how to implement such feature, but the design of Simple Injector 1 was too limiting to add such feature. The key feature that was missing from Simple Injector was explicit lifestyle support. For Simple Injector 1, users were expected to register custom `Func<T>` delegates that implement custom lifestyles, but passing on delegates disallowed the library to find out anything about the registered lifestyle. I experimented a lot with the analysis of expression trees (since the built-in lifestyles each contained their signature buried deep in the expression trees they built), but this was brittle and unreliable. Especially since part of Simple Injector’s extendibility is based on altering expression trees.

Simple Injector 2 adds explicit lifestyle support. This basically means that there is a Lifestyle base class and all lifestyles (such as Transient, Singleton, and everything in between) inherit from that base class. This paved the way for doing analysis. Side effect was that it allowed me to solve a broad range of bugs and limitations as well and made some new features considerably easier to implement. For instance, for some parts of the API it was originally extremely hard to register types with a custom lifestyle. Especially the more advanced scenarios such as decorator registration, open generic type mapping, and batch registration.

Although all the new features make the library more flexible and more complete, I still believe in exposing a minimalistic API and supplying users with a framework with a set of features with a default configuration that steers them to the pit of success. I learned this a long time ago from reading the [Framework Design Guidelines](https://www.amazon.com/Framework-Design-Guidelines-Conventions-Libraries/dp/0321545613).

One of those “default configuration that steers [developers] to the pit of success” examples is the lack of support for auto-wiring types with multiple public constructors. Having [multiple constructors is an anti-pattern](/steven/posts/2013/di-anti-pattern-multiple-constructors). I've communicated with a lot of developers that where annoyed about this and some even switched libraries. That’s okay; I don’t have to be the most popular DI library. I don’t want Simple Injector to become this Swiss army knife and I’ll stick with this strategy.

In general, your classes should have a single public constructor that contains all the services it depends on. Unfortunately, sometimes it’s not your own code that causes a class to have multiple constructors (when using T4MVC for instance). It is important to supply users with a way to change the default behavior as Simple Injector [does](https://simpleinjector.org/xtpcr).

But the point is, by not allowing this by default, Simple Injector forces developers to at least think about their design and perhaps even reconsider it. This is one of the main design principles behind Simple Injector.

The library on the other hand does have its quirks. The feature I regret the most ever having implemented is the Container.InjectProperties method. This method does implicit property injection, which means that it 'tries' to inject all public writable properties of a given object, but will skip any property that has a type hasn't been configured or hasn’t been configured correctly. The problem with this is that implicit property injection lead to a DI configuration that is hard to verify and an application that might fail at runtime instead. And in a sense, Simple Injector 2 made things worse, since properties injected using InjectProperties never show up in the new diagnostic results. Making it easy for developers to call InjectProperties certainly isn't an example of steering them into "the pit of success". This part is so important to me that I'm willing to introduce a breaking change again and remove InjectProperties from the library. However, don't worry; this won't happen before there is a good alternative. But if you're using InjectProperties today, please reconsider its use. If in doubt, please ask on Stackoverflow.

## What’s next?

I’m taking the commitment on maintaining Simple Injector for the coming years and I will stick to the design principles I previously stated.

Multiple developers have complained about the source control solution for Simple Injector which is FTS on CodePlex. They are absolutely right. It must become easier to fork and accept contributions. I will definitely switch to the distributed source control system Git and CodePlex already has support for this. But please be patient, the Visual Studio 2012 Git integration is still in beta, and I’ll make the switch after it has gone RTM.

And of course there are plans for future versions of Simple Injector. For instance, in the next minor release (2.1) I’ll probably add a strategy to allow adding explicit property injection that integrates well with the diagnostic services (just as there already are strategies for constructor resolution and injection). What’s great about the current 2.0 is that the new `ExpressionBuilding` event already completely allows you implement explicit property injection (including diagnostic integration), but I like to make this more straightforward to do this in a reliable and fast way.

Another part that I will definitely keep investigating in is the new Diagnostic Services. New warning types will be added in the future. If you have any ideas for improvements the Diagnostic Service, let’s discuss it on the [Simple Injector discussion forum](https://simpleinjector.org/forum).

As always, happy injecting!

## No comments