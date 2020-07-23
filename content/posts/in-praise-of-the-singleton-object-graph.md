---
title:			"In Praise of the Singleton Object Graph"
date:			2019-08-07
author: 		Steven van Deursen
reviewers:		Peter Parker and Ric Slappendel
proofreaders:	Katie Tennant
tags:			[.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId:	6
draft:			false
aliases:
    - /p/singleton
---

### To be able to achieve anything useful, your application code makes use of runtime data that comes in many shapes and forms. Providing access to that data can be accomplished in many ways. The way you provide object graphs with runtime data can affect the way you compose them using Dependency Injection. There are two competing models to choose from. This article suggests the use of the less common, more restrictive model, as it helps you reason about the correctness of the graph and reduces the chance of errors. This article is the last of a five-part series on Dependency Injection composition models.

Posts in this series:

* [DI Composition Models: A Primer](/steven/p/compositionmodels)
* [The Closure Composition Model](/steven/p/ccm)
* [The Ambient Composition Model](/steven/p/acm)
* [DI Composition Models: A Comparison](/steven/p/cmcompare)
* [In Praise of the Singleton Object Graph](/steven/p/singleton) (this article)

The previous articles did a deep dive into the two DI composition models: the Closure Composition Model (CCM) and the Ambient Composition Model (ACM). The last article compared the two composition models, and explained the merits and demerits of both models.

The following table summarizes their strengths and weaknesses, as discussed in the previous article.

|                         | CCM using Pure DI      | CCM using a DI Container | ACM                       |
| ----------------------- | --------------------- | ---------------------- | ------------------------- |
| **Environment limitations** | Might be unsuited when dealing with tight memory constraints | Might be unsuited when dealing with tight memory constraints | Unsuited  in environments that don’t allow storing ambient data |
| **Temporal Coupling**   | + No                   | - Always               | - Always                  |
| **Lifetime Management** | + Simple for small applications {{<br>}} -  Error prone for large applications | - Always error prone   | + Always simple           |
| **Code reviews**        | - Hard                 | - Hard                 | + Easy                    |
| **Performance**         | + High                 | - Complex to manage    | + High                    |
| **Acquaintance**        | - Well known           | - Well known           | - Less known              |

In keeping with what I concluded in the previous article, this summary of strengths and weaknesses of both models reveals no absolute winner under all circumstances. In recent years, however, I started to appreciate the ACM more and more, because of its listed advantages. While building and maintaining [Simple Injector](https://simpleinjector.org), I spent an enormous amount of time adding features that prevent developers from stepping into the many pitfalls of DI Lifetime Management. But eventually I started to wonder whether this required tooling was actually an indication of a problem with the underlying model, just as badly designed code often forces the use of mocking frameworks during unit testing.

One might even argue that DI Containers themselves are not the right solution, and there is certainly some truth in that because, as I explained in the comparison, applying [Pure DI](https://blog.ploeh.dk/2014/06/10/pure-di/)  in combination with the CCM gives the strongest guarantee about the availability of runtime data. But, on the other hand, lifetime management with the CCM is hard—even in the context of Pure DI.

In [our book](https://mng.bz/BYNl), [Mark](https://blog.ploeh.dk/) and I give the following advice:

> [Y]ou should use Pure DI for Composition Roots that are small and switch to Auto-Registration [thus, using a DI Container] when maintaining such a Composition Root becomes a problem. Bigger applications with many classes that can be captured by several conventions can benefit from using Auto-Registration. [§ 12.3.3]

In the book, however, we don’t define “small” and “bigger.” The fact is, though, that in recent years I realized that the ACM allowed me to stick with using Pure DI for a longer period of time. Pure DI [Composition Roots](https://mng.bz/K1qZ) grow linearly with the size of the application. In my opinion, it is a good idea to start a new application without using a container—thus practicing Pure DI. When an application keeps growing, however, there comes a point in time when using a DI Container outperforms Pure DI. But I noticed that using the ACM moved this tipping point, allowing me to use Pure DI on bigger applications.

This doesn’t mean that I think DI Containers are worthless—on the contrary, they can be immensely powerful. But I found that the ACM allows me to postpone the decision of whether or not to use a DI Container for much longer—possibly forever, depending on the size and structure of the final application.

Although both models can be mixed and matched, it is when the ACM is used holistically that you see a simplified composition model emerge. I would, therefore, like to suggest that you start embracing its constraints:

{{% callout TIP %}}
make your components stateless and immutable, hide the retrieval of runtime data behind abstractions, and implement those abstractions using adapters in your Composition Root.
{{% /callout %}}

You can even follow this recipe when you’re not applying the ACM. The advantage of this is that the choice of which model to use becomes purely an implementation detail of the Composition Root. This means you can postpone the decision of which model to use until [the last responsible moment](https://blog.codinghorror.com/the-last-responsible-moment/). It even allows you to switch from one model to the next, without having to make any changes outside your Composition Root.

While you could still use the CCM, choose to apply the ACM by default. By doing so, you can start reusing your object graphs (using the Singleton Lifestyle). This makes constructing object graphs a one-time cost, *and* makes it easy to spot when ACM’s constraints are violated. In other words: *apply the ACM and embrace singleton object graphs.*

## Conclusion

Even though no single model is perfect in all circumstances, the ACM is my preferred composition model for the majority of cases. Although you might find some resistance from your team members or suffer incompatibility with your frameworks at first, when you start applying the ACM, you’ll find that its constraints capture a mental model that is simpler to grasp and results in fewer bugs. This will boost your team’s productivity.

## Comments